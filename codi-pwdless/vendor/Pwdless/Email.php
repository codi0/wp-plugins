<?php

namespace Pwdless;

class Email {

	public static $defaults = [
		'pwdless_email_ml_login' => [
			'email_ml_login_subject' => 'Login to {site_name}',
			'email_ml_login_body' => "Click the link below to sign in now:\n\n{magic_link}\n\nThis link is single use and will expire in {magic_expiry} minutes. If you did not request this login link, please reply to this email.\n\nThanks,\nThe {site_name} Team",
		],
		'pwdless_email_ml_reg' => [
			'email_ml_reg_subject' => 'Activate your account at {site_name}',
			'email_ml_reg_body' => "Click the link below to activate your account:\n\n{magic_link}\n\nThis link is single use and will expire in {magic_expiry} minutes. If you did not request this activation link, please reply to this email.\n\nThanks,\nThe {site_name} Team",
		],
		'pwdless_email_sso_reg' => [
			'email_sso_reg_subject' => 'Welcome to {site_name}',
			'email_sso_reg_body' => "Your account is now ready to use:\n\n{home_link}\n\nThanks,\nThe {site_name} Team",
		]
	];

    public function send($type, $email, array $tokens = []) {
		//valid email?
		if(!isset(self::$defaults[$type])) {
			return;
		}

        // Set default tokens
        $tokens['email'] = $email;
        $tokens['home_link'] = home_url();
        $tokens['site_name'] = get_option('blogname');
        
        //get keys
        $defaults = self::$defaults[$type];
        $keys = array_keys($defaults);
        
        $opts = get_option('oidc_sso', []);
        $subject_tpl = ($opts[$keys[0]] ?? '') ?: $defaults[$keys[0]];
        $body_tpl    = ($opts[$keys[1]] ?? '') ?: $defaults[$keys[1]];

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

}