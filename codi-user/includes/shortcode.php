<?php

//login form shortcode
function codi_user_shortcode_login($atts, $content='') {
	return codi_user_form_login($atts ?: []);
}

//logout form shortcode
function codi_user_shortcode_logout($atts, $content='') {
	return codi_user_form_logout($atts ?: []);
}

//password form shortcode
function codi_user_shortcode_password($atts, $content='') {
	return codi_user_form_password($atts ?: []);
}

//register form shortcode
function codi_user_shortcode_register($atts, $content='') {
	return codi_user_form_register($atts ?: []);
}

//profile form shortcode
function codi_user_shortcode_profile($atts, $content='') {
	return codi_user_form_profile($atts ?: []);
}

//activation details shortcode
function codi_user_shortcode_activate($atts, $content='') {
	//is logged in?
	if(!$user = wp_get_current_user()) {
		return;
	}
	//set vars
	$activated = get_user_meta($user->ID, 'activated', true);
	$resent = isset($_GET['checkemail']) && $_GET['checkemail'] === 'resent';
	//is activated?
	if($activated && isset($_GET['activated'])) {
		echo '<div class="notice success">';
		echo __('Thank you, your email address has now been verified.');
		echo '</div>';
	}
	//email resent?
	if(!$activated && $resent) {
		echo '<div class="notice success">';
		echo sprintf(__('Activation email sent to %s'), esc_html($user->user_email));
		echo '</div>';
	}
	//check email?
	if(!$activated && !$resent) {
		echo '<div class="notice info">';
		echo __('Please check your email to activate your account. If you haven\'t received it yet, check your spam or <a href="?checkemail=resend">click here to resend</a>.');
		echo '</div>';
	}
}

//add shortcodes
add_shortcode('codi_login', 'codi_user_shortcode_login');
add_shortcode('codi_logout', 'codi_user_shortcode_logout');
add_shortcode('codi_password', 'codi_user_shortcode_password');
add_shortcode('codi_register', 'codi_user_shortcode_register');
add_shortcode('codi_profile', 'codi_user_shortcode_profile');
add_shortcode('codi_activation_email', 'codi_user_shortcode_activate');