<?php

//constants
define('CODI_MAIL_SES_VERSION', 2);

function codi_mail_ses_version() {
	return (defined('CODI_MAIL_SES_VERSION') && CODI_MAIL_SES_VERSION == 2) ? 2 : 1;
}

function codi_mail_ses_normalize_lines($value) {
	if(is_array($value)) {
		return $value;
	}

	$value = str_replace("\r\n", "\n", (string)$value);
	return $value === '' ? [] : array_map('trim', explode("\n", $value));
}

function codi_mail_ses_mime_type($path) {
	if(function_exists('wp_check_filetype')) {
		$filetype = wp_check_filetype($path);
		if(!empty($filetype['type'])) {
			return $filetype['type'];
		}
	}

	if(function_exists('mime_content_type')) {
		$type = @mime_content_type($path);
		if(is_string($type) && $type !== '') {
			return $type;
		}
	}

	return 'application/octet-stream';
}

function codi_mail_ses_error_message($response) {
	$error = null;

	if(is_object($response)) {
		$error = $response->error ?? null;
	} elseif(is_array($response)) {
		$error = $response['error'] ?? $response;
	}

	if(is_object($error)) {
		$error = (array)$error;
	}

	if(is_array($error)) {
		if(!empty($error['message']) && is_scalar($error['message'])) {
			return (string)$error['message'];
		}

		$detail = $error['Error'] ?? null;
		if(is_object($detail)) {
			$detail = (array)$detail;
		}
		if(is_array($detail)) {
			foreach(['Message', 'message'] as $key) {
				if(!empty($detail[$key]) && is_scalar($detail[$key])) {
					return (string)$detail[$key];
				}
			}
		}
	}

	return 'Amazon SES did not accept the email.';
}

function codi_mail_ses_failed($message, array $mail_data, $code = 0) {
	if($code) {
		$mail_data['ses_exception_code'] = $code;
	}

	do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $message, $mail_data));
	return false;
}

//override send mail
add_filter('pre_wp_mail', function($return, $atts) {
	//already done?
	if($return !== null) {
		return $return;
	}

	//load classes?
	if(!class_exists('SimpleEmailService')) {
		//get version
		$version = 'v' . codi_mail_ses_version();
		//load files
		include_once(__DIR__ . '/ses/' . $version . '/SimpleEmailService.php');
		include_once(__DIR__ . '/ses/' . $version . '/SimpleEmailServiceMessage.php');
		include_once(__DIR__ . '/ses/' . $version . '/SimpleEmailServiceRequest.php');
	}

	//normalise wp_mail arguments
	$to = $atts['to'] ?? [];
	$subject = (string)($atts['subject'] ?? '');
	$message = (string)($atts['message'] ?? '');
	$headers = codi_mail_ses_normalize_lines($atts['headers'] ?? []);
	$attachments = $atts['attachments'] ?? [];
	$embeds = $atts['embeds'] ?? [];

	if(!is_array($to)) {
		$to = $to ? array_map('trim', explode(',', $to)) : [];
	}
	if(!is_array($attachments)) {
		$attachments = codi_mail_ses_normalize_lines($attachments);
	}
	if(!is_array($embeds)) {
		$embeds = codi_mail_ses_normalize_lines($embeds);
	}

	$mail_data = compact('to', 'subject', 'message', 'headers', 'attachments', 'embeds');

	//set vars
	$opts = codi_mail_opts();
	$from_name = get_bloginfo('name');
	$from_email = $opts['from'] ?: get_bloginfo('admin_email');

	//create message
	$msg = new SimpleEmailServiceMessage();

	//loop through headers
	foreach($headers as $h) {
		if(!is_scalar($h)) {
			continue;
		}

		$h = trim((string)$h);
		if($h === '') {
			continue;
		}

		//add header
		$msg->addCustomHeader($h);

		//get from name and email?
		if(stripos($h, 'From:') === 0) {
			$parts = str_replace([ 'From:', '>' ], '', $h);
			$parts = explode('<', trim($parts));
			if(isset($parts[1]) && strpos($parts[1], '@') > 0) {
				$from_email = trim($parts[1]);
				$from_name = trim(str_replace('"', '', $parts[0]));
			} elseif(strpos($parts[0], '@') > 0) {
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
		if(!is_scalar($t)) {
			continue;
		}

		$t = trim((string)$t);
		if($t === '') {
			continue;
		}

		//remove whitespace?
		if(strpos($t, '<') === false) {
			$t = preg_replace('/\s+/', '', $t);
		}

		$msg->addTo($t);
	}

	//set subject/message using the existing Codi Mail body semantics
	$msg->setSubject($subject);
	$msg->setMessageFromString($message);

	//set attachments
	foreach($attachments as $filename => $path) {
		if(!is_scalar($path)) {
			continue;
		}

		$path = (string)$path;
		$name = is_string($filename) ? $filename : basename($path);
		$msg->addAttachmentFromFile($name, $path, codi_mail_ses_mime_type($path));
	}

	//set embedded files
	foreach($embeds as $key => $path) {
		if(!is_scalar($path)) {
			continue;
		}

		$path = (string)$path;
		$args = apply_filters('wp_mail_embed_args', [
			'path' => $path,
			'cid' => (string)$key,
			'name' => basename($path),
			'encoding' => 'base64',
			'type' => '',
			'disposition' => 'inline',
		]);

		if(
			!is_array($args)
			|| empty($args['path'])
			|| !array_key_exists('cid', $args)
			|| (string)$args['cid'] === ''
		) {
			continue;
		}

		$type = !empty($args['type']) ? (string)$args['type'] : codi_mail_ses_mime_type((string)$args['path']);
		$cid = trim((string)$args['cid'], '<>');
		$msg->addAttachmentFromFile(
			(string)($args['name'] ?? basename((string)$args['path'])),
			(string)$args['path'],
			$type,
			'<' . $cid . '>',
			(string)($args['disposition'] ?? 'inline')
		);
	}

	//validate before the SES library can emit a PHP warning
	if(method_exists($msg, 'validate') && !$msg->validate()) {
		return codi_mail_ses_failed('Email message failed validation.', $mail_data);
	}

	//send
	try {
		//create service without PHP-warning side effects for normal SES failures
		$ses = new SimpleEmailService($opts['username'], $opts['password'], $opts['host'], false);
		$raw = (bool)$headers || $attachments || $embeds;
		$response = $ses->sendEmail($msg, $raw, false);

		if(!is_array($response) || empty($response['MessageId'])) {
			return codi_mail_ses_failed(codi_mail_ses_error_message($response), $mail_data);
		}

		return true;
	} catch(\Throwable $e) {
		return codi_mail_ses_failed($e->getMessage(), $mail_data, $e->getCode());
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
	$html .= '<tr><td>API Version</td><td>v' . codi_mail_ses_version() . '</td></tr>' . "\n";
	$html .= '<tr><td>Region</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Access key ID</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Secret access key</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
	//return
	return $html;
}, 10, 2);
