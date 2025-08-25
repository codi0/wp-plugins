<?php

namespace Pwdless;

class MagicLink {

    const ACTION_PARAM = 'magic_login';
    const TOKEN_TTL    = 900; // seconds

    protected $orchestrator;
    protected $throttle;

    public function __construct(Login $orchestrator) {
        $this->orchestrator = $orchestrator;
        $this->throttle = new Throttle;
        add_action('init', [ $this, 'maybe_handle_login' ]);
    }

	public function check_wait($email, $saveHistory = true) {
		return $this->throttle->check_wait($email, $_SERVER['REMOTE_ADDR'], $saveHistory);
	}

    public function send_link($email) {
        $email = sanitize_email($email);

        if (!is_email($email)) {
            return false;
        }

        // Is this a new user?
        $is_new = $this->orchestrator->is_new_user($email);
        $type = 'magiclink_' . ($is_new ? 'registration' : 'login');

		//send email
		return (new Email)->send($type, $email, [
			'magic_expiry' => self::TOKEN_TTL / 60,
			'magic_link' => $this->get_magic_link($email),
		]);
    }

	public function get_magic_link($email) {
		return add_query_arg([
			self::ACTION_PARAM => 1,
			'token' => $this->generate_token($email),
		], $this->orchestrator->get_base_url());
	}

    public function maybe_handle_login() {
        if (empty($_GET[self::ACTION_PARAM]) || empty($_GET['token'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['token']));
        $email = $this->validate_token($token);
        if (!$email) {
            wp_die('Invalid or expired magic login link.');
        }

        delete_transient($this->transient_key($token));
        
        $identity = [
			'email' => $email,
        ];

		$settings = get_option('oidc_sso', []);

        // Hand off to orchestrator for unified flow
        $user = $this->orchestrator->login_or_register($identity, [
			'roles' => $settings['shared_default_roles'] ?? 'subscriber',
        ]);

        if (is_wp_error($user)) {
            wp_die($user);
        }
        
        $this->throttle->clear_history($email);

        // Redirect if orchestrator didn't already
        wp_safe_redirect($this->default_opts['redirect_to'] ?? $this->orchestrator->get_redirect_url());
        exit;
    }

    /**
     * Create signed token and store server-side.
     */
    protected function generate_token($email): string {
        $payload = base64_encode(json_encode([
            'email' => $email,
            'iat'   => time(),
        ]));
        $sig   = hash_hmac('sha256', $payload, wp_salt('auth'));
        $token = $payload . '.' . $sig;

        set_transient($this->transient_key($token), $email, self::TOKEN_TTL);
        return $token;
    }

    /**
     * Return email if token is valid and not expired.
     */
    protected function validate_token($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        [$payload_b64, $sig] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload_b64, wp_salt('auth')), $sig)) {
            return null;
        }

        $payload = json_decode(base64_decode($payload_b64), true);
        if (empty($payload['email']) || !is_email($payload['email'])) {
            return null;
        }

        $email = sanitize_email($payload['email']);
        return get_transient($this->transient_key($token)) === $email ? $email : null;
    }

    protected function transient_key(string $token): string {
        return 'magic_login_' . md5($token);
    }

}