<?php

//process user activation
function codi_user_activate_process() {
	//has key?
	if(!isset($_GET['key']) || !$_GET['key']) {
		return;
	}
	//has login?
	if(!isset($_GET['login']) || !$_GET['login']) {
		return;
	}
	//is reset password
	if(isset($_GET['action']) && $_GET['action'] == 'rp') {
		return;
	}
	//get user
	$user = get_user_by('login', $_GET['login']);
	//valid user?
	if(!$user || !$user->ID) {
		return;
	}
	//is activated?
	if(!get_user_meta($user->ID, 'activated', true)) {
		//query activation key
		$errors = check_password_reset_key($_GET['key'], $_GET['login']);
		//has errors?
		if(is_wp_error($errors)) {
			//new email sent?
			if(isset($_GET['resent'])) {
				//resend confirmation message
				echo '<p>' . __('A new activation link has been sent to your email.') . '</p>' . "\n";
				echo '<p><a href="' . esc_url(home_url()) . '">' . __('Continue to site') . '</a></p>' . "\n";
				exit();
			} else if(isset($_GET['resend'])) {
				//resend email
				codi_user_activate_email($user->ID);
				//redirect user
				$url = str_replace('resend', 'resent', $_SERVER['REQUEST_URI']);
				wp_safe_redirect($url);
				exit();	
			} else {
				//set url
				$url = add_query_arg('resend', 'true', $_SERVER['REQUEST_URI']);
				//display error message
				echo '<p>' . __('Your activation link has expired. You can request a new link below.') . '</p>' . "\n";
				echo '<p><a href="' . esc_url($url) . '">' . __('Send new activation email') . '</a></p>' . "\n";
				exit();
			}
		}
		//mark as activated
		codi_user_activate_success($user);
	}
	//build redirect url
	$url = add_query_arg('activated', 'true', wp_login_url());
	//redirect to login
	wp_safe_redirect($url);
	exit();
}

//resend activation email
function codi_user_activate_resend() {
	//get user
	$user = wp_get_current_user();
	//valid request?
	if(!$user || !isset($_GET['checkemail']) || $_GET['checkemail'] !== 'resend') {
		return;
	}
	//is activated?
	if(get_user_meta($user->ID, 'activated', true)) {
		return;
	}
	//resend email
	codi_user_activate_email($user->ID);
	//redirect user
	$url = str_replace('resend', 'resent', $_SERVER['REQUEST_URI']);
	wp_safe_redirect($url);
	exit();	
}

//send activation email
function codi_user_activate_email($user_id) {
	//get user
	$user = get_user_by('id', $user_id);
	$activated = get_user_meta($user_id, 'activated', true);
	//stop here?
	if(!$user || !$user->user_email || $activated) {
		return false;
	}
	//set url
	$url = add_query_arg([
		'key' => get_password_reset_key($user),
		'login' => rawurlencode($user->user_login),
	], home_url());
	//set email
	$email = [
		'to' => $user->user_email,
		'subject' => '[' . get_bloginfo('name') . '] ' . __('Activate your account'),
		'message' => $user->display_name . ', ' . __('please click on the link below to complete your registration') . ':' . "\n\n" . $url,
		'headers' => '',
	];
	//filter message
	$email = apply_filters(__FUNCTION__, $email, $user);
	//send mail
	return wp_mail($email['to'], $email['subject'], $email['message'], $email['headers']);
}

//mark user as activated
function codi_user_activate_success($user) {
	//is activated?
	if(get_user_meta($user->ID, 'activated', true)) {
		return;
	}
	//update user
	wp_update_user([
		'ID' => $user->ID,
		'user_activation_key' => '',
	]);
	//set meta data
	update_user_meta($user->ID, 'activated', 1);
	//return
	do_action(__FUNCTION__, $user);
}

//activation hooks
add_action('init', 'codi_user_activate_process');
add_action('init', 'codi_user_activate_resend');
add_action('codi_user_activate', 'codi_user_activate_email');
add_action('after_password_reset', 'codi_user_activate_success');