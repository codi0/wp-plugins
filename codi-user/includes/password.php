<?php

//add custom field
add_filter('codi_user_form_opts', function($opts) {
	//is registration?
	if(!isset($opts['type']) || $opts['type'] !== 'register') {
		return $opts;
	}
	//use password?
	if(!isset($opts['password']) || $opts['password'] !== 'true') {
		return $opts;
	}
	//add captcha field
	codi_user_extend('user_pass', [
		'label' => 'Password',
		'forms' => [ $opts['type'] ],
		'admin' => false,
		'position' => 5,
		'render' => function($name, $value, $user) {
			echo '<input type="password" name="{name}" id="{name}" value="{value}" autocomplete="new-password">';
		},
		'validate' => function($name, $value, $user, $errors) {
			//valid password?
			if(strlen($value) < 6) {
				$errors->add('invalid_password', __('<strong>Error</strong>: your password must be at least 6 characters.'));
			}
			//disable new user notification
			add_filter('wp_new_user_notification_email', function($email) {
				//blank recipient
				$email['to'] = '';
				//return
				return $email;
			});
			//disable change password notification
			add_filter('send_password_change_email', '__return_false');
		},
		'save' => function($name, $value, $user) {
			//after registration complete
			add_action('register_new_user', function($user_id) use($value) {
				//update record
				wp_update_user([
					'ID' => $user_id,
					'user_pass' => $value,
				]);
				//not default password
				delete_user_meta($user_id, 'default_password_nag');
				//execute hook
				do_action('codi_user_activate', $user_id);
				//login user
				wp_set_auth_cookie($user_id);
			}, 999);
		},
	]);
	//return
	return $opts;
});