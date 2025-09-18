<?php

//override send mail
add_filter('pre_wp_mail', function($return, $atts) {
	//already done?
	if($return !== null) {
		return $return;
	}
	//load classes?
	if(!class_exists('SimpleEmailService')) {
		include_once(__DIR__ . '/ses/SimpleEmailService.php');
		include_once(__DIR__ . '/ses/SimpleEmailServiceMessage.php');
		include_once(__DIR__ . '/ses/SimpleEmailServiceRequest.php');
	}
	//extract
	extract($atts);
	//set vars
	$opts = codi_mail_opts();
	$headers = $headers ?: [];
	$attachments = $attachments ?: [];
	$from_name = get_bloginfo('name');
	$from_email = $opts['from'] ?: get_bloginfo('admin_email');
	//format to?
	if(!is_array($to)) {
		$to = $to ? array_map('trim', explode(',', $to)) : [];
	}
	//format headers?
	if(!is_array($headers)) {
		$headers = str_replace("\r\n", "\n", $headers);
		$headers = array_map('trim', explode("\n", $headers));
	}
	//format attachments?
    if(!is_array($attachments)) {
		$attachments = str_replace("\r\n", "\n", $attachments);
		$attachments = array_map('trim', explode("\n", $attachments));
	}
	//create message
	$msg = new SimpleEmailServiceMessage();
	//loop through headers
	foreach($headers as $h) {
		//add header
		$msg->addCustomHeader($h);
		//get from name and email?
		if(stripos($h, 'From:') === 0) {
			$parts = str_replace([ 'From:', '>' ], '', $h);
			$parts = explode('<', trim($parts));
			if(isset($parts[1]) && strpos($parts[1], '@') > 0) {
				$from_email = trim($parts[1]);
				$from_name = trim(str_replace('"', '', $parts[0]));
			} else if(strpos($parts[0], '@') > 0) {
				$from_email = trim($parts[0]);
			}
		}
	}
	//filter from
	$from_email = apply_filters('wp_mail_from', $from_email);
	$from_name = apply_filters('wp_mail_from_name', $from_name);
	//set from
	$msg->setFrom($from_name . ' <' . $from_email . '>');
	//set to
	foreach($to as $t) {
		//remove whitespace?
		if(strpos($t, '<') === false) {
			$t = preg_replace('/\s+/', '', $t);
		}
		//add recipient?
		$msg->addTo($t);
	}
	//set subject
	$msg->setSubject($subject);
	//set message
	$msg->setMessageFromString($message);
	//set attachments
	foreach($attachments as $a) {
		$name = basename($a);
		$msg->addAttachmentFromFile($name, $a);
	}
	//send
	try {
		//create service
		$ses = new SimpleEmailService($opts['username'], $opts['password'], $opts['host']);
		//send raw?
		$raw = (bool) $headers || $attachments;
		//send message
		return !!$ses->sendEmail($msg, $raw);
	} catch (Exception $e) {
		//set data
		$mail_error_data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		$mail_error_data['ses_exception_code'] = $e->getCode();
		//execute action
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $e->getMessage(), $mail_error_data));
		//return
		return false;
	}
}, 9999, 2);

//filter mail options
add_filter('codi_mail_opts', function($opts) {
	//is SES?
	if($opts['type'] === 'ses') {
		//add amazon domain?
		if($opts['host'] && strpos($opts['host'], '.') === false) {
			$opts['host'] = 'email.' . $opts['host'] . '.amazonaws.com';
		}
		//remove smtp reference
		$opts['host'] = str_replace('email-smtp', 'email', $opts['host']);
	}
	//return
	return $opts;
});

//filter admin fields
add_filter('codi_mail_admin_fields', function($html, $opts) {
	//is SES?
	if($opts['type'] !== 'ses') {
		return $html;
	}
	//reset fields
	$html  = '';
	//add SES fields
	$html .= '<tr><td>About</td><td style="font-size:0.9em; font-style:italic;">This uses the Amazon API to send emails, in cases where SMTP ports are blocked by your server: <a href="https://blog.mailtrap.io/amazon-ses-explained/#Step-by-Step_setup" target="_blank">Amazon SES setup guide</a></td></tr>' . "\n";
	$html .= '<tr><td>Region</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Access key ID</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Secret access key</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
	//return
	return $html;
}, 10, 2);