<?php

/*
Plugin Name: Codi Contact Form
Description: Receive messages from your site visitors by email.
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//define constants
define('CODI_CONTACT_PLUGIN_FILE', __FILE__);
define('CODI_CONTACT_PLUGIN_NAME', basename(__DIR__));

//process shortcode
function codi_contact_shortcode($atts, $content='') {
	//set vars
	$form = '';
	$errors = [];
	$user = wp_get_current_user();
	$atts = is_array($atts) ? $atts: [];
	//post vars
	$name = isset($_POST['_name']) ? trim($_POST['_name']) : '';
	$email = isset($_POST['_email']) ? trim($_POST['_email']) : '';
	$message = isset($_POST['_message']) ? trim($_POST['_message']) : '';
	$nonce = isset($_POST['_nonce']) ? $_POST['_nonce'] : '';
	$honeypot = isset($_POST['_subject']) ? trim($_POST['_subject']) : '';
	//set user details?
	if(is_user_logged_in()) {
		$name = $user->display_name;
		$email = $user->user_email;
	}
	//process update?
	if(isset($_POST['_action']) && $_POST['_action'] === 'contact') {
		//valid nonce?
		if(!wp_verify_nonce($nonce, 'contact_form')) {
			$errors[] = __('Invalid form request. Please try again.');
		}
		//valid email?
		if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errors[] = __('Please enter a valid email address.');
		}
		//valid message?
		if(!$message) {
			$errors[] = __('Please enter your message.');
		}
		//send email?
		if(!$errors) {
			//recipients
			$to = apply_filters('codi_contact_emails', [ get_option('admin_email') ]);
			//subject
			$subject = get_bloginfo('name') . ' ' . __('contact form') . ($name ? ': ' . $name : '');
			//message
			$prefix  = '';
			$prefix .= __('Name') . ': ' . ($name ?: 'n/a') . "\n";
			$prefix .= __('Email') . ': ' . $email . "\n\n";
			//headers
			$headers = 'Reply-To: ' . $email;
			//send email
			foreach($to as $t) {
				!$honeypot && wp_mail($t, $subject, $prefix . $message, $headers);
			}
			//message successfully sent
			$form .= '<div class="notice success">';
			$form .= __('Thank you for your message. We will get back to you shortly.');
			$form .= '</div>';
			//reset vars
			$name = $email = $message = '';
		}
	}
	//has errors?
	if($errors) {
		//open notice
		$form .= '<div class="notice error">';
		//loop through errors
		foreach($errors as $error) {
			$form .= '<div class="item">' . $error . '</div>';
		}
		//close notice
		$form .= '</div>';
	}
	//display form
	$form .= '<form method="post">';
	$form .= '<input type="hidden" name="_action" value="contact">';
	$form .= wp_nonce_field('contact_form', '_nonce', true, false);
	$form .= apply_filters('codi_contact_form_top', '', $atts);
	if(!is_user_logged_in()) {
		$form .= '<div class="field name">';
		$form .= '<label for id="contact-name">' . __('Your name') . '</label>';
		$form .= '<input type="text" name="_name" id="contact-name" value="' . esc_attr($name) . '">';
		$form .= '</div>';
		$form .= '<div class="field email">';
		$form .= '<label for id="contact-email">' . __('Your email') . '*</label>';
		$form .= '<input type="text" name="_email" id="contact-email" value="' . esc_attr($email) . '">';
		$form .= '</div>';
	} else {
		$form .= '<p>Hi ' . esc_html($name) . ', add your message below:</p>';
	}
	$form .= '<div class="field subject" style="display:none;">';
	$form .= '<label for id="contact-subject">' . __('Subject') . '</label>';
	$form .= '<input type="text" name="_subject" id="contact-subject" value="' . esc_attr($honeypot) . '">';
	$form .= '</div>';
	$form .= apply_filters('codi_contact_form_middle', '', $atts);
	$form .= '<div class="field message">';
	if(!is_user_logged_in()) {
		$form .= '<label for id="contact-message">' . __('Your message') . '*</label>';
	}
	$form .= '<textarea name="_message" id="contact-message">' . esc_html($message) . '</textarea>';
	$form .= '</div>';
	$form .= apply_filters('codi_contact_form_bottom', '', $atts);
	$form .= '<div class="field submit">';
	$form .= '<input type="submit" value="' . __('Send message') . '">';
	$form .= '</div>';
	$form .= '</form>';
	//return
	return $form;
}

//init
add_shortcode('codi-contact', 'codi_contact_shortcode');