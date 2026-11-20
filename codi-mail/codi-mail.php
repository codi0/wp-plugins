<?php

/*
Plugin Name: Codi Mail
Description: Send WordPress email through SMTP or Amazon SES and optionally receive Microsoft 365 mail through Microsoft Graph.
Version: 1.2.5
Requires at least: 5.7
Requires PHP: 7.1
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') || exit;

define('CODI_MAIL_PLUGIN_FILE', __FILE__);
define('CODI_MAIL_PLUGIN_NAME', basename(__DIR__));
define('CODI_MAIL_VERSION', '1.2.5');

function codi_mail_defaults() {
	return [
		'type' => 'smtp',
		'host' => '',
		'port' => 587,
		'protocol' => 'tls',
		'username' => '',
		'password' => '',
		'from' => get_bloginfo('admin_email'),
	];
}

function codi_mail_sanitize_opts(array $opts) {
	$defaults = codi_mail_defaults();
	$type = isset($opts['type']) ? sanitize_key((string)$opts['type']) : $defaults['type'];

	if(!in_array($type, [ 'smtp', 'ses' ], true)) {
		$type = $defaults['type'];
	}

	$host = isset($opts['host']) ? trim(sanitize_text_field((string)$opts['host'])) : '';
	if($type === 'ses' && $host !== '') {
		$host = strtolower($host);
		if(strpos($host, '.') === false) {
			if(strpos($host, 'cn-') === 0) {
				$suffix = 'amazonaws.com.cn';
			} elseif(strpos($host, 'eusc-') === 0) {
				$suffix = 'amazonaws.eu';
			} else {
				$suffix = 'amazonaws.com';
			}

			$host = 'email.' . $host . '.' . $suffix;
		}
		$host = str_replace('email-smtp.', 'email.', $host);
	}

	$port = isset($opts['port']) ? absint($opts['port']) : $defaults['port'];
	if($port < 1 || $port > 65535) {
		$port = $defaults['port'];
	}

	$protocol = isset($opts['protocol']) ? strtolower(trim((string)$opts['protocol'])) : $defaults['protocol'];
	if(!in_array($protocol, [ '', 'tls', 'ssl' ], true)) {
		$protocol = $defaults['protocol'];
	}

	$clean = [
		'type' => $type,
		'host' => $host,
		'port' => $port,
		'protocol' => $protocol,
		'username' => isset($opts['username']) ? trim((string)$opts['username']) : '',
		'password' => isset($opts['password']) ? (string)$opts['password'] : '',
	];

	$from = isset($opts['from']) ? sanitize_email((string)$opts['from']) : '';
	if($from !== '' && $from !== get_bloginfo('admin_email')) {
		$clean['from'] = $from;
	}

	return $clean;
}

function codi_mail_opts(?array $opts = null) {
	if(is_array($opts)) {
		$opts = apply_filters(__FUNCTION__, $opts);
		$opts = codi_mail_sanitize_opts($opts);
		update_option(__FUNCTION__, $opts);
	} else {
		$opts = get_option(__FUNCTION__, []);
		if(!is_array($opts)) {
			$opts = [];
		}
	}

	return array_merge(
		codi_mail_defaults(),
		codi_mail_sanitize_opts(array_merge(codi_mail_defaults(), $opts))
	);
}

function codi_mail_default_from($atts) {
	$opts = codi_mail_opts();
	$adminEmail = sanitize_email((string)get_bloginfo('admin_email'));
	$defaultEmail = isset($opts['from']) && is_email($opts['from'])
		? sanitize_email((string)$opts['from'])
		: $adminEmail;

	if($defaultEmail === '' || !is_email($defaultEmail)) {
		return $atts;
	}

	$headers = $atts['headers'] ?? [];
	$lines = is_array($headers)
		? $headers
		: explode("\n", str_replace("\r\n", "\n", (string)$headers));

	foreach($lines as $index => $header) {
		if(!is_scalar($header) || !preg_match('/^\\s*from\\s*:\\s*(.*)$/i', (string)$header, $match)) {
			continue;
		}

		$value = trim($match[1]);
		if(preg_match('/<([^>]+)>/', $value, $addressMatch)) {
			$fromEmail = sanitize_email(trim($addressMatch[1]));
		} else {
			$fromEmail = sanitize_email(trim($value, " \t\n\r\0\x0B\"'"));
		}

		if($adminEmail === '' || strcasecmp($fromEmail, $adminEmail) !== 0) {
			return $atts;
		}

		$from = codi_mail_admin_from_header($adminEmail);
		if(is_array($headers)) {
			$headers[$index] = $from;
			$atts['headers'] = $headers;
		} else {
			$lines[$index] = $from;
			$atts['headers'] = implode("\r\n", $lines);
		}
		return $atts;
	}

	$from = ($adminEmail !== '' && strcasecmp($defaultEmail, $adminEmail) === 0)
		? codi_mail_admin_from_header($adminEmail)
		: 'From: ' . $defaultEmail;

	if(is_array($headers)) {
		$headers[] = $from;
	} elseif(trim((string)$headers) === '') {
		$headers = $from;
	} else {
		$headers = rtrim((string)$headers, "\r\n") . "\r\n" . $from;
	}

	$atts['headers'] = $headers;
	return $atts;
}

function codi_mail_admin_from_header($adminEmail) {
	$name = str_replace([ "\r", "\n" ], '', wp_strip_all_tags((string)get_bloginfo('name')));
	return 'From: ' . $name . ' <' . $adminEmail . '>';
}

function codi_mail_html_content_type($atts) {
	$headers = $atts['headers'] ?? [];
	$lines = is_array($headers)
		? $headers
		: explode("\n", str_replace("\r\n", "\n", (string)$headers));

	foreach($lines as $header) {
		if(is_scalar($header) && preg_match('/^\\s*content-type\\s*:/i', (string)$header)) {
			return $atts;
		}
	}

	$message = isset($atts['message']) ? (string)$atts['message'] : '';
	if(wp_strip_all_tags($message) === $message) {
		return $atts;
	}

	$contentType = 'Content-Type: text/html; charset=UTF-8';

	if(is_array($headers)) {
		$headers[] = $contentType;
	} elseif(trim((string)$headers) === '') {
		$headers = $contentType;
	} else {
		$headers = rtrim((string)$headers, "\r\n") . "\r\n" . $contentType;
	}

	$atts['headers'] = $headers;
	return $atts;
}

function codi_mail_load_driver($driver = null) {
	$opts = codi_mail_opts();
	$driver = $driver ?: $opts['type'];

	if(!in_array($driver, [ 'smtp', 'ses' ], true)) {
		return;
	}

	$file = __DIR__ . '/driver/' . $driver . '.php';
	if(is_file($file)) {
		require_once $file;
	}
}

function codi_mail_header_lines($headers) {
	if(is_array($headers)) {
		return array_values(array_filter(array_map(function($header) {
			return is_scalar($header) ? trim((string)$header) : '';
		}, $headers), 'strlen'));
	}

	return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", (string)$headers))), 'strlen'));
}

function codi_mail_phpmailer_custom_headers($mailer) {
	if(!is_object($mailer) || !method_exists($mailer, 'getCustomHeaders')) {
		return [];
	}

	$result = [];
	foreach((array)$mailer->getCustomHeaders() as $header) {
		if(!is_array($header) || !isset($header[0], $header[1]) || !is_scalar($header[0]) || !is_scalar($header[1])) {
			continue;
		}
		$name = trim((string)$header[0]);
		$value = trim((string)$header[1]);
		if($name !== '' && $value !== '') {
			$result[] = $name . ': ' . $value;
		}
	}

	return array_values(array_unique($result));
}

function codi_mail_address_list($addresses) {
	$result = [];

	foreach((array)$addresses as $address) {
		if(is_array($address) && !empty($address[0])) {
			$result[] = (string)$address[0];
		} elseif(is_scalar($address) && trim((string)$address) !== '') {
			$result[] = trim((string)$address);
		}
	}

	return array_values(array_unique($result));
}

function codi_mail_emit_sent($mail_data) {
	global $phpmailer;

	if(!class_exists('Codi_Mail_PHPMailer', false) || !($phpmailer instanceof Codi_Mail_PHPMailer)) {
		return;
	}

	$transport = method_exists($phpmailer, 'codiTransportResult')
		? $phpmailer->codiTransportResult()
		: [];
	$provider = isset($transport['provider']) ? (string)$transport['provider'] : '';

	if($provider === '') {
		return;
	}

	$messageId = array_key_exists('message_id', $transport)
		? (string)$transport['message_id']
		: (method_exists($phpmailer, 'getLastMessageID') ? (string)$phpmailer->getLastMessageID() : '');

	$result = [
		'provider' => $provider,
		'message_id' => $messageId,
		'provider_message_id' => isset($transport['provider_message_id']) ? (string)$transport['provider_message_id'] : '',
		'to' => method_exists($phpmailer, 'getToAddresses') ? codi_mail_address_list($phpmailer->getToAddresses()) : codi_mail_address_list($mail_data['to'] ?? []),
		'cc' => method_exists($phpmailer, 'getCcAddresses') ? codi_mail_address_list($phpmailer->getCcAddresses()) : [],
		'bcc' => method_exists($phpmailer, 'getBccAddresses') ? codi_mail_address_list($phpmailer->getBccAddresses()) : [],
		'from' => isset($phpmailer->From) ? (string)$phpmailer->From : '',
		'headers' => array_values(array_unique(array_merge(
			codi_mail_header_lines($mail_data['headers'] ?? []),
			codi_mail_phpmailer_custom_headers($phpmailer)
		))),
		'sent_at' => gmdate('c'),
	];

	if(!empty($transport['request_id'])) {
		$result['request_id'] = (string)$transport['request_id'];
	}
	if(array_key_exists('duration_ms', $transport)) {
		$result['duration_ms'] = max(0, (int)$transport['duration_ms']);
	}

	do_action('codi_mail/sent', $result);
}

add_filter('wp_mail', 'codi_mail_default_from', PHP_INT_MAX);
add_filter('wp_mail', 'codi_mail_html_content_type', PHP_INT_MAX);
add_action('plugins_loaded', 'codi_mail_load_driver', -9999);
add_action('wp_mail_succeeded', 'codi_mail_emit_sent', PHP_INT_MAX);

require_once __DIR__ . '/inbound.php';

register_activation_hook(__FILE__, 'codi_mail_inbound_activate');
register_deactivation_hook(__FILE__, 'codi_mail_inbound_deactivate');

if(is_admin()) {
	require_once __DIR__ . '/admin.php';
}
