<?php

//add custom field
add_filter('codi_user_form_opts', function($opts) {
	//stop here?
	if(!isset($opts['captcha']) || $opts['captcha'] !== 'true') {
		return $opts;
	}
	//add captcha field
	codi_user_extend('g-recaptcha-response', [
		'label' => '',
		'forms' => [ $opts['type'] ],
		'admin' => false,
		'position' => 999,
		'render' => function($name, $value, $user) {
			//set vars
			$captcha = get_option('codi_captcha', [ 'site_key' => '', 'secret_key' => '' ]);
			//add captcha?
			if($captcha['site_key']) {
				echo '<div class="g-recaptcha field" data-sitekey="' . esc_attr($captcha['site_key']) . '"></div>';
				echo '<script defer async src="https://www.google.com/recaptcha/api.js"></script>';
			}
		},
		'validate' => function($name, $value, $user, $errors) {
			//set vars
			$success = false;
			$captcha = get_option('codi_captcha', []);
			//stop here?
			if(!$captcha || $errors->has_errors()) {
				return;
			}
			//make request
			$request = wp_remote_request('https://www.google.com/recaptcha/api/siteverify', [
				'method' => 'POST',
				'body' => [
					'secret' => isset($captcha['secret_key']) ? $captcha['secret_key'] : '',
					'response' => $value,
				],
			]);
			//process request?
			if(is_array($request)) {
				//decode json
				$json = json_decode($request['body'], true);
				//is valid?
				if($json && isset($json['success']) && $json['success']) {
					$success = true;
				}
			}
			//add error?
			if(!$success) {
				$errors->add('captcha', __('<strong>Error</strong>: unable to verify you are not a robot.'));
			}
		},
	]);
	//return
	return $opts;
});