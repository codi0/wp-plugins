<?php

/*
Plugin Name: Codi Mail
Description: Send WordPress emails through SMTP or Amazon SES.
Version: 1.0.11
Requires at least: 5.7
Requires PHP: 7.1
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') || exit;

define('CODI_MAIL_PLUGIN_FILE', __FILE__);
define('CODI_MAIL_PLUGIN_NAME', basename(__DIR__));

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

	if($opts['from'] === '' || !is_email($opts['from'])) {
		return $atts;
	}

	$headers = $atts['headers'] ?? [];
	$lines = is_array($headers)
		? $headers
		: explode("\n", str_replace("\r\n", "\n", (string)$headers));

	foreach($lines as $header) {
		if(is_scalar($header) && preg_match('/^\\s*from\\s*:/i', (string)$header)) {
			return $atts;
		}
	}

	$from = 'From: ' . $opts['from'];

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

add_filter('wp_mail', 'codi_mail_default_from', PHP_INT_MAX);
add_filter('wp_mail', 'codi_mail_html_content_type', PHP_INT_MAX);
add_action('plugins_loaded', 'codi_mail_load_driver', -9999);

if(is_admin()) {
	require_once __DIR__ . '/admin.php';
}
