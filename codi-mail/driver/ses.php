<?php

//ammazon ses driver
function codi_mail_driver_ses($to, $subject, $message, $headers = '', $attachments = array()) {
	//load classes?
	if(!class_exists('SimpleEmailService')) {
		include_once(__DIR__ . '/ses/SimpleEmailService.php');
		include_once(__DIR__ . '/ses/SimpleEmailServiceMessage.php');
		include_once(__DIR__ . '/ses/SimpleEmailServiceRequest.php');
	}
	//set vars
	$opts = codi_mail_opts();
	$headers = $headers ?: [];
	$attachments = $attachments ?: [];
	//filter inputs
	$atts = apply_filters('wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ));
    //do not send mail?
    if($atts === false) {
		return false;
    }
	//extract again?
	if($atts && is_array($atts)) {
		extract($atts);
	}
	//create message
	$msg = new SimpleEmailServiceMessage();
	//format from
	$from_email = apply_filters('wp_mail_from', $opts['from'] ?: get_bloginfo('admin_email'));
	$from_name = apply_filters('wp_mail_from_name', get_bloginfo('name'));
	//set from
	$msg->setFrom($from_name . ' <' . $from_email . '>');
	//format to?
	if(!is_array($to)) {
		$to = array_map('trim', explode(',', $to));
	}
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
	//format headers?
	if(!is_array($headers)) {
		$headers = str_replace("\r\n", "\n", $headers);
		$headers = array_map('trim', explode("\n", $headers));
	}
	//set headers
	foreach($headers as $h) {
		$msg->addCustomHeader($h);
	}
	//format attachments?
    if(!is_array($attachments)) {
		$attachments = str_replace("\r\n", "\n", $attachments);
		$attachments = array_map('trim', explode("\n", $attachments));
	}
	//set attachments
	foreach($attachments as $a) {
		$name = basename($a);
		$msg->addAttachmentFromFile($name, $a);
	}
	//send
	try {
		//format host
		$host = str_replace('smtp-', '', $opts['host']);
		//create service
		$ses = new SimpleEmailService($opts['username'], $opts['password'], $host);
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
}

//admin settings form
function codi_mail_driver_ses_admin(array $opts) {
	echo '<tr><td>About</td><td style="font-size:0.9em; font-style:italic;">This uses the Amazon API to send emails, in cases where SMTP ports are blocked by your server: <a href="https://blog.mailtrap.io/amazon-ses-explained/#Step-by-Step_setup" target="_blank">Amazon SES setup guide</a></td></tr>' . "\n";
	echo '<tr><td>Region</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
	echo '<tr><td>Access key ID</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
	echo '<tr><td>Secret access key</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
}

//filter options
add_filter('codi_mail_opts', function($opts) {
	//filter host?
	if($opts['type'] === 'ses' && $opts['host'] && strpos($opts['host'], '.') === false) {
		$opts['host'] = 'email.' . $opts['host'] . '.amazonaws.com';
	}
	//return
	return $opts;
});