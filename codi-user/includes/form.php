<?php

//login form
function codi_user_form_login(array $opts=[]) {
	//set vars
	$form = '';
	$errors = '';
	$user_login = isset($_POST['log']) ? $_POST['log'] : '';
	$user_pwd = isset($_POST['pwd']) ? $_POST['pwd'] : '';
	$remember_me = isset($_POST['rememberme']) ? $_POST['rememberme'] : '';
	//set opts
	$opts = codi_user_form_opts($opts, [
		'type' => 'login',
		'title' => '',
		'class' => '',
		'captcha' => 'false',
		'redirect' => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '',
	]);
	//filter redirect
	$opts['redirect'] = apply_filters('login_redirect', $opts['redirect']);
	//can login?
	if(is_user_logged_in()) {
		wp_safe_redirect($opts['redirect'] ?: admin_url());
		exit();
	}
	//process login?
	if(isset($_POST['action']) && $_POST['action'] === 'login') {
		//check authentication
		$errors = wp_authenticate($user_login, $user_pwd);
		//filter errors
		$errors = apply_filters('login_errors', is_wp_error($errors) ? $errors : new WP_Error);
		//has errors?
		if(!$errors->has_errors()) {
			//sign in
			$errors = wp_signon([ 'user_login' => $user_login, 'user_password' => $user_pwd ]);
			//redirect user?
			if(!is_wp_error($errors)) {
				wp_safe_redirect($opts['redirect'] ?: admin_url());
				exit();
			}
		}
	}
	//build form
	$form .= '<div id="login_form_wrap" class="login-wrap ' . $opts['class'] . '">' . "\n";
	//show title?
	if($opts['title']) {
		$form .= '<h2>' . esc_html__($opts['title']) . '</h2>';
	}
	//show errors?
	if(!empty($errors)) {
		$form .= codi_user_form_errors($errors);
	}
	$form .= '<form method="post" name="loginform" id="loginform">' . "\n";
	$form .= '<input type="hidden" name="action" value="login">' . "\n";
	$form .= apply_filters('login_form_top', '', $opts);
	$form .= '<p id="user_login_field" class="field">' . "\n";
	$form .= '<label for="user_login">' . __('Username or Email Address') . '</label>' . "\n";
	$form .= '<input type="text" name="log" id="user_login" class="input" value="' . esc_attr($user_login) . '">' . "\n";
	$form .= '</p>' . "\n";
	$form .= '<p id="user_pass_field" class="field">' . "\n";
	$form .= '<label for="user_pass">' . __('Password') . '</label>' . "\n";
	$form .= '<input type="password" name="pwd" id="user_pass" class="input" value="' . esc_attr($user_pwd) . '">' . "\n";
	$form .= '</p>' . "\n";
	$form .= '<p id="rememberme_field" class="field">' . "\n";
	$form .= '<label><input name="rememberme" type="checkbox" id="rememberme" value="forever"' . ($remember_me ? ' checked' : '') . '> ' . __('Remember Me') . '</label>' . "\n";
	$form .= '</p>' . "\n";
	ob_start();
	do_action('login_form');
	$form .= ob_get_clean();
	$form .= apply_filters('login_form_middle', '', $opts);
	$form .= '<p id="submit_field" class="field submit">' . "\n";
	$form .= '<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary" value="' . __('Sign in') . '">' . "\n";
	$form .= '</p>' . "\n";
	$form .= apply_filters('login_form_bottom', '', $opts);
	$form .= '</form>' . "\n";
	$form .= '<div class="login-links">' . "\n";
	if(get_option('users_can_register')) {
		$form .= '<div>' . "\n";
		$form .= '<a href="' . wp_registration_url() . '">' . __('Create an account') . '</a>' . "\n";
		$form .= '</div>' . "\n";
	}
	$form .= '<div>' . "\n";
	$form .= '<a href="' . wp_lostpassword_url() . '">' . __('Forgotten password') . '</a>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	//return
	return apply_filters(__FUNCTION__, $form, $opts);
}

//logout form
function codi_user_form_logout(array $opts=[]) {
	//set opts
	$opts = codi_user_form_opts($opts, [
		'type' => 'logout',
		'redirect' => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '',
	]);
	//filter redirect
	$opts['redirect'] = apply_filters('logout_redirect', $opts['redirect']);
	//run logout
	wp_logout();
	//redirect user
	wp_safe_redirect($opts['redirect'] ?: wp_login_url());
	exit();
}

//password form
function codi_user_form_password(array $opts=[]) {
	//set vars
	$form = '';
	$errors = '';
	$user_login = isset($_POST['user_login']) ? $_POST['user_login'] : '';
	//set opts
	$opts = codi_user_form_opts($opts, [
		'type' => 'password',
		'title' => '',
		'class' => '',
		'captcha' => 'false',
		'redirect' => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '',
	]);
	//filter redirect
	$opts['redirect'] = apply_filters('lost_password_redirect', $opts['redirect']);
	//can recover password?
	if(is_user_logged_in()) {
		wp_safe_redirect($opts['redirect'] ?: admin_url());
		exit();
	}
	//check email?
	if(isset($_GET['checkemail']) && $_GET['checkemail'] === 'confirm') {
		//build form
		$form .= '<div class="notice info message">' . "\n";
		$form .= __('Please check your email address to reset your password.');
		$form .= '</div>' . "\n";
		//return
		return $form;
	}
	//process lost password?
	if(isset($_POST['action']) && $_POST['action'] === 'lost_password') {
		//set vars
		$user_data = false;
		$errors = new WP_Error;
		//validate input
		if(empty($_POST['user_login']) || !is_string($_POST['user_login'])) {
			$errors->add('empty_username', __('<strong>Error</strong>: Please enter a username or email address.'));
		} else if(strpos($_POST['user_login'], '@')) {
			$user_data = get_user_by('email', trim(wp_unslash($_POST['user_login'])));
			if(empty($user_data)) {
				$errors->add('invalid_email', __('<strong>Error</strong>: There is no account with that username or email address.'));
			}
		} else {
			$login = trim(wp_unslash($_POST['user_login']));
			$user_data = get_user_by('login', $login);
		}
		//before errors checked
		do_action('lostpassword_post', $errors, $user_data);
		//filter errors
		$errors = apply_filters('lostpassword_errors', $errors, $user_data);
		//has errors?
		if(!$errors || !$errors->has_errors()) {
			//has data?
			if(!$user_data) {
				$errors->add('invalidcombo', __('<strong>Error</strong>: There is no account with that username or email address.'));
			} else {
				//set vars
				$user_login = $user_data->user_login;
				$user_email = $user_data->user_email;
				$key = get_password_reset_key($user_data);
				//has error?
				if(is_wp_error($key)) {
					$errors = $key;
				} else {
					//set site name
					if(is_multisite()) {
						$site_name = get_network()->site_name;
					} else {
						$site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
					}
					//set email title
					$title = sprintf(__('[%s] Password Reset'), $site_name);
					$title = apply_filters('retrieve_password_title', $title, $user_login, $user_data);
					//set email message
					$message  = sprintf(__('A password reset was requested for your %s account. To reset your password, click the link below:'), $site_name) . "\r\n\r\n";
					$message .= network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login') . "\r\n\r\n";
					$message .= __('If you did not request this, please ignore this email.');
					//filter message
					$message = apply_filters('retrieve_password_message', $message, $key, $user_login, $user_data);
					//send message?
					if($message && !wp_mail($user_email, wp_specialchars_decode($title), $message)) {
						$errors->add(
							'retrieve_password_email_failure',
							sprintf(
								__('<strong>Error</strong>: The email could not be sent. Your site may not be correctly configured to send emails. <a href="%s">Get support for resetting your password</a>.'),
								esc_url(__('https://wordpress.org/support/article/resetting-your-password/'))
							)
						);
					} else {
						//success
						$errors = '';
					}
				}
			}
		}
		//has errors?
		if(!is_wp_error($errors)) {
			$url = add_query_arg('checkemail', 'confirm', $_SERVER['REQUEST_URI']);
			wp_safe_redirect($opts['redirect'] ?: $url);
			exit();
		}
	}
	//build form
	$form .= '<div id="password_form_wrap" class="login-wrap ' . $opts['class'] . '">' . "\n";
	//show title?
	if($opts['title']) {
		$form .= '<h2>' . esc_html__($opts['title']) . '</h2>';
	}
	//show errors?
	if(!empty($errors)) {
		$form .= codi_user_form_errors($errors);
	}
	$form .= '<form method="post" name="lostpasswordform" id="lostpasswordform">';
	$form .= '<input type="hidden" name="action" value="lost_password">';
	$form .= apply_filters('lostpassword_form_top', '', $opts);
	$form .= '<p id="user_login_field" class="field">';
	$form .= '<label for="user_login">' . __('Username or Email Address') . '</label>';
	$form .= '<input type="text" name="user_login" id="user_login" class="input" value="' . esc_attr($user_login) . '" autocapitalize="off">';
	$form .= '</p>';
	ob_start();
	do_action('lostpassword_form');
	$form .= ob_get_clean();
	$form .= apply_filters('lostpassword_form_middle', '', $opts);
	$form .= '<p id="submit_field" class="field submit">';
	$form .= '<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="' . __('Get new password') . '">';
	$form .= '</p>';
	$form .= apply_filters('lostpassword_form_bottom', '', $opts);
	$form .= '</form>';
	$form .= '<div class="login-links">' . "\n";
	$form .= '<div>' . "\n";
	$form .= '<a href="' . wp_login_url() . '">' . __('Back to sign in') . '</a>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	//return
	return apply_filters(__FUNCTION__, $form, $opts);
}

//register form
function codi_user_form_register(array $opts=[]) {
	//set vars
	$form = '';
	$errors = '';
	$user_login = isset($_POST['user_login']) ? $_POST['user_login'] : '';
	$user_email = isset($_POST['user_email']) ? $_POST['user_email'] : '';
	//set opts
	$opts = codi_user_form_opts($opts, [
		'type' => 'register',
		'title' => '',
		'class' => '',
		'captcha' => 'false',
		'password' => 'false',
		'redirect' => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '',
	]);
	//filter redirect
	$opts['redirect'] = apply_filters('registration_redirect', $opts['redirect']);
	//can register?
	if(!get_option('users_can_register')) {
		wp_safe_redirect(home_url());
		exit();
	}
	//is logged in?
	if(is_user_logged_in()) {
		wp_safe_redirect($opts['redirect'] ?: admin_url());
		exit();
	}
	//check email?
	if(isset($_GET['checkemail']) && $_GET['checkemail'] === 'registered') {
		//build form
		$form .= '<div class="notice info message">' . "\n";
		$form .= __('Please check your email address to complete registration.');
		$form .= '</div>' . "\n";
		//return
		return $form;
	}
	//process registration?
	if(isset($_POST['action']) && $_POST['action'] === 'register') {
		//register without password
		$errors = register_new_user($user_login, $user_email);
		//has errors?
		if(!is_wp_error($errors)) {
			//redirect user
			$url = add_query_arg('checkemail', 'registered', $_SERVER['REQUEST_URI']);
			wp_safe_redirect($opts['redirect'] ?: $url);
			exit();
		}
	}
	//build form
	$form .= '<div id="register_form_wrap" class="login-wrap ' . $opts['class'] . '">' . "\n";
	//show title?
	if($opts['title']) {
		$form .= '<h2>' . esc_html__($opts['title']) . '</h2>';
	}
	//show errors?
	if(!empty($errors)) {
		$form .= codi_user_form_errors($errors);
	}
	$form .= '<form method="post" name="registerform" id="registerform">';
	$form .= '<input type="hidden" name="action" value="register">';
	$form .= apply_filters('register_form_top', '', $opts);
	$form .= '<p id="user_login_field" class="field">';
	$form .= '<label for="user_login">' . __('Username') . '</label>';
	$form .= '<input type="text" name="user_login" id="user_login" class="input" value="' . esc_attr($user_login) . '" autocapitalize="off">';
	$form .= '</p>';
	$form .= '<p id="user_email_field" class="field">';
	$form .= '<label for="user_email">' . __('Email') . '</label>';
	$form .= '<input type="email" name="user_email" id="user_email" class="input" value="' . esc_attr($user_email) . '">';
	$form .= '</p>';
	ob_start();
	do_action('register_form');
	$form .= ob_get_clean();
	$form .= apply_filters('register_form middle', '', $opts);
	$form .= '<p id="submit_field" class="field submit">';
	$form .= '<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="' . __('Create account') . '">';
	$form .= '</p>';
	$form .= apply_filters('register_form_bottom', '', $opts);
	$form .= '</form>';
	$form .= '<div class="login-links">' . "\n";
	$form .= '<div>' . "\n";
	$form .= '<a href="' . wp_login_url() . '">' . __('Sign in') . '</a>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '<div>' . "\n";
	$form .= '<a href="' . wp_lostpassword_url() . '">' . __('Forgotten password') . '</a>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	$form .= '</div>' . "\n";
	//return
	return apply_filters(__FUNCTION__, $form, $opts);
}

//profile form
function codi_user_form_profile(array $opts=[]) {
	//set opts
	$opts = codi_user_form_opts($opts, [
		'type' => 'profile',
		'title' => '',
		'class' => '',
		'password' => 'true',
		'user_id' => get_current_user_id(),
	]);
	//set vars
	$form = '';
	$errors = '';
	$success = '';
	$user = get_user_by('id', $opts['user_id']);
	$display_name = codi_user_form_input('display_name', $user);
	$user_email = codi_user_form_input('user_email', $user);
	$user_pass = codi_user_form_input('user_pass');
	$nonce = codi_user_form_input('_wpnonce');
	//is logged in?
	if(!is_user_logged_in()) {
		wp_safe_redirect(wp_login_url());
		exit();
	}
	//process update?
	if(isset($_POST['action']) && $_POST['action'] === 'profile' && wp_verify_nonce($nonce, 'profile_form')) {
		//filter errors
		$errors = apply_filters('profile_errors', new WP_Error, true, $user);
		//has errors?
		if(!$errors || !$errors->has_errors()) {
			//set ID
			$_POST['ID'] = $user->ID;
			//update user
			$res = wp_update_user($_POST);
			//has error?
			if(is_wp_error($res)) {
				$errors = $res;
			} else {
				$success = 'Profile successfully updated';
			}
		}
	}
	//build form
	$form .= '<div id="profile_form_wrap" class="' . $opts['class'] . '">' . "\n";
	//show title?
	if($opts['title']) {
		$form .= '<h2>' . esc_html__($opts['title']) . '</h2>';
	}
	//show success?
	if(!empty($success)) {
		$form .= '<div class="notice success">';
		$form .= esc_html__($success);
		$form .= '</div>';
	}
	//show errors?
	if(!empty($errors)) {
		$form .= codi_user_form_errors($errors);
	}
	$form .= '<form method="post" name="profileform" id="profileform">' . "\n";
	$form .= '<input type="hidden" name="action" value="profile">' . "\n";
	$form .= wp_nonce_field('profile_form', '_wpnonce', true, false) . "\n";
	$form .= apply_filters('profile_form_top', '', $opts);
	$form .= '<p id="display_name_field" class="field">'. "\n";
	$form .= '<label for="display_name">' . __('Display name') . '</label>'. "\n";
	$form .= '<input type="text" name="display_name" id="display_name" class="input" value="' . esc_attr($display_name) . '">'. "\n";
	$form .= '</p>'. "\n";
	$form .= '<p id="user_email_field" class="field">' . "\n";
	$form .= '<label for="user_email">' . __('Email') . '</label>' . "\n";
	$form .= '<input type="email" name="user_email" id="user_email" class="input" value="' . esc_attr($user_email) . '">' . "\n";
	$form .= '</p>' . "\n";
	ob_start();
	do_action('profile_form', $user);
	$form .= ob_get_clean();
	$form .= apply_filters('profile_form middle', '', $opts);
	if($opts['password'] === 'true') {
		$form .= '<p id="user_pass_field" class="field">' . "\n";
		$form .= '<label for="user_pass">' . __('Change password?') . '</label>' . "\n";
		$form .= '<input type="password" name="user_pass" id="user_pass" class="input" value="' . esc_attr($user_pass) . '" autocomplete="new-password">' . "\n";
		$form .= '</p>' . "\n";
	}
	$form .= '<p id="submit_field" class="field submit">' . "\n";
	$form .= '<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="' . __('Update profile') . '">' . "\n";
	$form .= '</p>' . "\n";
	$form .= apply_filters('profile_form_bottom', '', $opts);
	$form .= '</form>'. "\n";
	$form .= '</div>'. "\n";
	//return
	return apply_filters(__FUNCTION__, $form, $opts, $user);
}

//display errors
function codi_user_form_errors($errors) {
	//set vars
	$form = '';
	$errMsgs = '';
	//stop here?
	if(!$errors || !$errors->has_errors()) {
		return $form;
	}
	//loop through error codes
	foreach($errors->get_error_codes() as $code) {
		//loop through error messages
		foreach($errors->get_error_messages($code) as $err) {
			$errMsgs .= $err;
			$errMsgs .= '<br>';
		}
	}
	//add errors?
	if(!empty($errMsgs)) {
		$form .= '<div class="notice error">';
		$form .= apply_filters(__FUNCTION__, $errMsgs);
		$form .= '</div>';
	}
	//return
	return $form;
}

//format form opts
function codi_user_form_opts($opts, array $defaults = []) {
	//merge opts
	$opts = array_merge($defaults, (array) $opts);
	//return
	return apply_filters(__FUNCTION__, $opts);
}

//get form input
function codi_user_form_input($key, $user=null) {
	//get user
	$user = codi_user_form_user($user);
	//post var?
	if(isset($_POST[$key])) {
		return $_POST[$key];
	}
	//stop here?
	if(!$user || !$user->ID) {
		return null;
	}
	//key found?
	if(isset($user->$key)) {
		return $user->$key;
	}
	//check meta
	return get_user_meta($user->ID, $key, true);
}

//get user object
function codi_user_form_user($user) {
	//is ID?
	if(is_numeric($user)) {
		return get_user_by('id', $user);
	}
	//invalid object?
	if(!is_object($user)) {
		return null;
	}
	//is stdclass?
	if($user instanceof stdClass) {
		$user = get_user_by('id', isset($user->ID) ? $user->ID : 0);
	}
	//return
	return $user;
}