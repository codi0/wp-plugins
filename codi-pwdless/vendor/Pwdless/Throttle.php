<?php

namespace Pwdless;

class Throttle {

    const EMAIL_FREE_ATTEMPTS = 2; // Free attempts before penalties
    const EMAIL_MAX_TIER = 5; // Max escalation tier
    const EMAIL_BASE_PENALTY = 30; // 30s per tier (tier 1 = 30s, tier 2 = 60s, etc.)
    
    const IP_TIME_WINDOW = 900; // 15-minute window for IP attempts
    const IP_MAX_ATTEMPTS = 10; // Max attempts before IP penalty
    const IP_PENALTY = 300; // 5-minute IP cooldown
    
    /**
     * Check if an attempt is currently allowed.
     * Returns null if allowed, or array with wait times if blocked.
     */
    public function check_wait(string $email, string $ip): ?array {
        $email_wait = $this->get_email_wait($email);
        $ip_wait = $this->get_ip_wait($ip);
        
        $res = [
            'time'  => max($email_wait, $ip_wait),
            'email' => $email_wait,
            'ip'    => $ip_wait,
        ];
        
        return $res['time'] > 0 ? $res : null;
    }
    
    /**
     * Record an attempt for the given email and IP.
     */
    public function record_attempt(string $email, string $ip): void {
        $this->record_email_attempt($email);
        $this->record_ip_attempt($ip);
    }
    
    /**
     * Clear throttle history for an email and optionally an IP.
     */
    public function clear_history(string $email, ?string $ip = null): void {
        delete_transient($this->get_email_key($email));
        if ($ip !== null) {
            delete_transient($this->get_ip_key($ip));
        }
    }

	// --- Email Throttling ---

	protected function get_email_wait(string $email): int {
		$key = $this->get_email_key($email);
		$data = get_transient($key);
		
		if ($data === false) {
			return 0;
		}
		
		$now = time();
		if (isset($data['cooldown_until']) && $data['cooldown_until'] > $now) {
			return $data['cooldown_until'] - $now;
		}
		
		return 0;
	}

	protected function record_email_attempt(string $email): void {
		$key = $this->get_email_key($email);
		$data = get_transient($key);
		$now = time();
		
		// Initialize default clean state
		$clean_state = [
			'free_count' => 0,
			'violation_count' => 0,
		];
		
		if ($data === false) {
			// First time ever — start clean
			$data = $clean_state;
		} else {
			// Check if there was a cooldown and it has expired
			$had_cooldown = isset($data['cooldown_until']);
			$cooldown_expired = $had_cooldown && $data['cooldown_until'] <= $now;
			
			if ($cooldown_expired) {
				// Cooldown period is over ? reset to clean state
				$data = $clean_state;
			}
			// If still in cooldown, keep current state (for escalation)
			// If never had cooldown (initial attempts), keep counting
		}
		
		// Determine if currently in cooldown (after potential reset)
		$in_cooldown = isset($data['cooldown_until']) && $data['cooldown_until'] > $now;
		
		if ($in_cooldown) {
			// Retry during active cooldown ? escalate
			$data['violation_count'] = min(($data['violation_count'] ?? 0) + 1, self::EMAIL_MAX_TIER);
			$penalty = $data['violation_count'] * self::EMAIL_BASE_PENALTY;
			$data['cooldown_until'] = $now + $penalty;
			// Do NOT increment free_count during cooldown
		} else {
			// Not in cooldown ? count as free attempt
			$data['free_count'] = ($data['free_count'] ?? 0) + 1;
			
			if ($data['free_count'] <= self::EMAIL_FREE_ATTEMPTS) {
				// Within free quota
				unset($data['cooldown_until']);
				$data['violation_count'] = 0;
			} else {
				// Exceeded free attempts ? trigger first penalty
				$data['violation_count'] = 1;
				$data['cooldown_until'] = $now + self::EMAIL_BASE_PENALTY;
			}
		}
		
		// Store with sufficient expiration
		$max_penalty = self::EMAIL_MAX_TIER * self::EMAIL_BASE_PENALTY;
		$expiration = $max_penalty + 60;
		set_transient($key, $data, $expiration);
	}

    // --- IP Throttling ---
    
    protected function get_ip_wait(string $ip): int {
        $key = $this->get_ip_key($ip);
        $data = get_transient($key);
        
        if ($data === false) {
            return 0;
        }
        
        $now = time();
        if (isset($data['cooldown_until']) && $data['cooldown_until'] > $now) {
            return $data['cooldown_until'] - $now;
        }
        
        return 0;
    }
    
    protected function record_ip_attempt(string $ip): void {
        $key = $this->get_ip_key($ip);
        $data = get_transient($key);
        $now = time();
        
        if ($data === false) {
            $data = ['attempts' => []];
        }
        
        $data['attempts'][] = $now;
        
        // Clean old attempts (sliding window)
        $window_start = $now - self::IP_TIME_WINDOW;
        $data['attempts'] = array_filter($data['attempts'], function($ts) use ($window_start) {
            return $ts >= $window_start;
        });
        $data['attempts'] = array_values($data['attempts']);
        
        $count = count($data['attempts']);
        if ($count > self::IP_MAX_ATTEMPTS) {
            $data['cooldown_until'] = $now + self::IP_PENALTY;
        } else {
            unset($data['cooldown_until']);
        }
        
        $expiration = self::IP_TIME_WINDOW + self::IP_PENALTY;
        set_transient($key, $data, $expiration);
    }
    
    // --- Key Generation ---
    
    protected function get_email_key(string $email): string {
        $email = trim(strtolower($email));
        return 'ml_req_' . md5($email);
    }
    
    protected function get_ip_key(string $ip): string {
        $ip = trim($ip);
        $bin = @inet_pton($ip);
        
        if ($bin !== false) {
            // Normalize IPv4-mapped IPv6 (e.g., ::ffff:192.0.2.1 ? 192.0.2.1)
            if (strlen($bin) === 16 && substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                $bin = substr($bin, 12);
            }
            return 'ml_ip_' . md5($bin);
        }
        
        // Fallback for malformed IPs (should be rare)
        return 'ml_ip_' . md5($ip);
    }

}