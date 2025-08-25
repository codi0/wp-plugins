<?php

namespace Pwdless;

class Email {

    public function send($type, $email, array $tokens = []) {

		try {
			[$subject_key, $body_key, $default_subject, $default_body] = $this->get_template_info($type);
		} catch(\Exception $e) {
			//exit early
			return;
		}

        // Set default tokens
        $tokens['email'] = $email;
        $tokens['home_link'] = home_url();
        $tokens['site_name'] = get_option('blogname');
        
        $opts = get_option('oidc_sso', []);
        $subject_tpl = ($opts[$subject_key] ?? '') ?: $default_subject;
        $body_tpl    = ($opts[$body_key] ?? '') ?: $default_body;

        // Replace tokens
        $subject = $this->format_text($subject_tpl, $tokens);
        $body = $this->format_text($body_tpl, $tokens);

        return wp_mail($email, $subject, $body);
    }

	protected function format_text($text, array $tokens) {
		return preg_replace_callback('/\{([a-z0-9_]+)\}/iU', function($m) use($tokens) {
			return $tokens[$m[1]] ?? '';
		}, $text);
	}

    protected function get_template_info($type) {
        switch ($type) {
            case 'magiclink_login':
                return [
                    'email_ml_login_subject',
                    'email_ml_login_body',
                    'Login to {site_name}',
                    "Click the link below to sign in now:\n\n{magic_link}\n\nThis link is single use and will expire in {magic_expiry} minutes. If you did not request this login link, please reply to this email.\n\nThanks,\nThe {site_name} Team",
                ];
            case 'magiclink_registration':
                return [
                    'email_ml_reg_subject',
                    'email_ml_reg_body',
                    'Activate your account at {site_name}',
                    "Click the link below to activate your account:\n\n{magic_link}\n\nThis link is single use and will expire in {magic_expiry} minutes. If you did not request this activation link, please reply to this email.\n\nThanks,\nThe {site_name} Team",
                ];
            case 'sso_registration':
                return [
                    'email_sso_reg_subject',
                    'email_sso_reg_body',
                    'Welcome to {site_name}',
                    "Your account is now ready to use:\n\n{home_link}\n\nThanks,\nThe {site_name} Team",
                ];
            default:
                throw new \InvalidArgumentException("Unknown email type $type");
        }
    }

}