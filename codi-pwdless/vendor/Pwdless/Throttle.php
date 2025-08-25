<?php

namespace Pwdless;

class Throttle {

    protected $email_cooldown = 30;
    protected $email_window = 600;

    protected $ip_max = 5;
    protected $ip_window = 600;
    

	public function check_wait($email, $ip, $saveHistory = true) {
		$now = time();

		// --- Per-email cooldown (state-machine fold) ---
		$email_history = $this->load_history($this->get_email_key($email), $this->email_window, $now);
		$email_wait = 0;

		// Helper to get cooldown for a specific tier; assumes calc clamps at max tier
		$cooldownFor = function(int $tier) {
			return $this->calc_email_cooldown($tier);
		};

		// Reconstruct state from history
		$tier = 1;                   // current tier (1-based)
		$cooldownEnd = -1;           // unix time when current cooldown ends
		$blockedInTier = 0;          // how many blocks since the last allowed attempt
		$lastAllowed = null;

		if (!empty($email_history)) {
			sort($email_history);
			foreach ($email_history as $t) {
				if ($lastAllowed === null) {
					// First attempt in history is always "allowed", starts tier 1 cooldown
					$tier = 1;
					$lastAllowed = $t;
					$cooldownEnd = $t + $cooldownFor(1);
					$blockedInTier = 0;
					continue;
				}

				if ($t >= $cooldownEnd) {
					// Cooldown served before this attempt -> allowed; reset to tier 1
					$tier = 1;
					$lastAllowed = $t;
					$blockedInTier = 0;
					$cooldownEnd = $t + $cooldownFor(1);
				} else {
					// Inside cooldown -> blocked
					if ($blockedInTier === 0) {
						// First early poke within this cooldown: freeze tier, keep cooldownEnd
						$blockedInTier = 1;
					} else {
						// Subsequent early poke -> escalate tier if it increases cooldown
						$nextTier = $tier + 1;
						$newCd = $cooldownFor($nextTier);
						$curCd = $cooldownFor($tier);
						if ($newCd > $curCd) {
							$tier = $nextTier;
							// New, longer cooldown starts from this attempt time
							$cooldownEnd = $t + $newCd;
						} else {
							// Already at max tier (or non-increasing) -> keep existing cooldownEnd
						}
						$blockedInTier++;
					}
				}
			}
		}

		// Evaluate the current attempt at $now
		if (empty($email_history)) {
			// No history: allow and start tier 1
			$email_wait = 0;
			$email_history[] = $now;
		} else {
			if ($now >= $cooldownEnd) {
				// Served: allow and reset to tier 1
				$email_wait = 0;
				if (end($email_history) !== $now) {
					$email_history[] = $now;
				}
				// Optional: you can prune history to just [$now] if you want to keep it tiny
				// $email_history = [$now];
			} else {
				// Blocked
				// First or subsequent early poke?
				if ($blockedInTier === 0) {
					// First early poke -> freeze tier, keep cooldownEnd
					$email_wait = max(1, $cooldownEnd - $now);
				} else {
					// Subsequent early poke -> escalate if it actually increases cooldown
					$nextTier = $tier + 1;
					$newCd = $cooldownFor($nextTier);
					$curCd = $cooldownFor($tier);
					if ($newCd > $curCd) {
						$tier = $nextTier;
						$cooldownEnd = $now + $newCd; // starts from this poke
					}
					$email_wait = max(1, $cooldownEnd - $now);
				}
				if (end($email_history) !== $now) {
					$email_history[] = $now; // log the attempt
				}
			}
		}

		// --- Global IP cap (unchanged) ---
		$ip_history = $this->load_history($this->get_ip_key($ip), $this->ip_window, $now);
		$ip_wait = 0;

		if (!empty($ip_history)) {
			if (count($ip_history) >= $this->ip_max) {
				$oldest  = min($ip_history);
				$elapsed = $now - $oldest;

				if ($elapsed < $this->ip_window) {
					// Still over the cap — block
					$ip_wait = max(1, $this->ip_window - $elapsed);
					$ip_history[] = $now;
				} else {
					// IP window served — wipe all history and start fresh
					$ip_history = [$now];
				}
			} else {
				$ip_history[] = $now;
			}
		} else {
			$ip_history[] = $now;
		}

		// Save attempt history if requested
		if ($saveHistory) {
			$this->save_history($this->get_email_key($email), $email_history, $this->email_window);
			$this->save_history($this->get_ip_key($ip), $ip_history, $this->ip_window);
		}

		// Allowed now if both waits are zero
		if ($email_wait <= 0 && $ip_wait <= 0) {
			return null;
		}

		return [
			'email' => $email_wait,
			'ip'    => $ip_wait
		];
	}

	public function clear_history($email, $ip = null) {
		// Remove per-email throttle
		delete_transient($this->get_email_key($email));
		// Optionally remove per-IP throttle
		if ($ip !== null) {
			delete_transient($this->get_ip_key($ip));
		}
	}

    protected function load_history($key, $window, $now) {
        $history = get_transient($key) ?: [];
        return array_filter($history, fn($ts) => ($now - $ts) <= $window);
    }

    protected function save_history($key, array $history, $window) {
        set_transient($key, $history, $window);
    }

    protected function calc_email_cooldown($attempts) {
        return min($this->email_cooldown * pow(2, $attempts - 1), $this->email_window);
    }

    protected function get_email_key($email) {
        return 'ml_req_' . md5(strtolower($email));
    }

    protected function get_ip_key($ip) {
        return 'ml_ip_' . md5(strtolower($ip));
    }

}