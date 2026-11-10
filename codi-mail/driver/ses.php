<?php

use CodiMail\Ses\Client;
use CodiMail\Ses\Request;

defined('ABSPATH') || exit;

function codi_mail_ses_load_phpmailer() {
	if(!class_exists('Codi_Mail_PHPMailer', false)) {
		require_once __DIR__ . '/phpmailer.php';
	}

	if(!class_exists('Codi_Mail_SES_PHPMailer', false)) {
		require_once __DIR__ . '/ses/phpmailer.php';
	}
}

function codi_mail_ses_load_client() {
	if(!class_exists('CodiMail\\Ses\\Client', false)) {
		require_once __DIR__ . '/ses/Client.php';
	}

	if(!class_exists('CodiMail\\Ses\\Request', false)) {
		require_once __DIR__ . '/ses/Request.php';
	}
}

function codi_mail_ses_error_data($response) {
	$data = [ 'provider' => 'ses' ];
	$error = null;

	if(is_object($response)) {
		if(!empty($response->code)) {
			$data['http_status'] = (int)$response->code;
		}
		$error = $response->error ?? null;
	} elseif(is_array($response)) {
		if(!empty($response['code'])) {
			$data['http_status'] = (int)$response['code'];
		}
		$error = $response['error'] ?? null;
	}

	if(is_object($error)) {
		$error = (array)$error;
	}

	if(!is_array($error)) {
		return $data;
	}

	if(!empty($error['curl'])) {
		$data['transport'] = 'curl';
		$data['transport_code'] = isset($error['code']) ? (int)$error['code'] : 0;
		$data['message'] = isset($error['message']) ? (string)$error['message'] : '';
		return array_filter($data, function($value) {
			return $value !== '';
		});
	}

	$detail = $error['Error'] ?? null;
	if(is_object($detail)) {
		$detail = (array)$detail;
	}

	if(is_array($detail)) {
		$data['ses_code'] = (string)($detail['Code'] ?? $detail['code'] ?? '');
		$data['ses_type'] = (string)($detail['Type'] ?? $detail['type'] ?? '');
		$data['message'] = (string)($detail['Message'] ?? $detail['message'] ?? '');
	}

	if(!empty($error['RequestId'])) {
		$data['request_id'] = (string)$error['RequestId'];
	}

	return array_filter($data, function($value) {
		return $value !== '';
	});
}

function codi_mail_ses_error_message($response) {
	$data = codi_mail_ses_error_data($response);
	return !empty($data['message']) ? $data['message'] : 'Amazon SES did not accept the email.';
}

function codi_mail_ses_service() {
	static $service = null;
	static $serviceKey = '';
	static $curlConfigured = false;

	if(!function_exists('curl_init')) {
		throw new RuntimeException('Codi Mail Amazon SES requires the PHP cURL extension.');
	}

	codi_mail_ses_load_client();

	$opts = codi_mail_opts();
	if($opts['host'] === '' || $opts['username'] === '' || $opts['password'] === '') {
		throw new RuntimeException('Codi Mail Amazon SES settings are incomplete.');
	}

	if(!$curlConfigured) {
		Request::$curlOptions = apply_filters('codi_mail_ses_curl_options', array_replace([
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_NOSIGNAL => true,
		], Request::$curlOptions));
		$curlConfigured = true;
	}

	$key = hash('sha256', implode("\0", [ $opts['username'], $opts['password'], $opts['host'] ]));
	if(!$service || $key !== $serviceKey) {
		$service = new Client($opts['username'], $opts['password'], $opts['host']);
		$serviceKey = $key;
	}

	return $service;
}

function codi_mail_ses_prepare_phpmailer($return, $atts) {
	if($return !== null) {
		return $return;
	}

	codi_mail_ses_load_phpmailer();

	global $phpmailer;

	if(!($phpmailer instanceof Codi_Mail_SES_PHPMailer)) {
		$phpmailer = new Codi_Mail_SES_PHPMailer(true);
		$phpmailer::$validator = static function($email) {
			return (bool)is_email($email);
		};
	}

	$phpmailer->codiResetSendState();
	Codi_Mail_SES_PHPMailer::$last_provider_error = [];
	return $return;
}

function codi_mail_ses_select_transport($phpmailer) {
	if(
		class_exists('Codi_Mail_SES_PHPMailer', false)
		&& $phpmailer instanceof Codi_Mail_SES_PHPMailer
	) {
		$phpmailer->Mailer = 'codiSes';
	}
}

function codi_mail_ses_enrich_failure($error) {
	if(
		!class_exists('Codi_Mail_SES_PHPMailer', false)
		|| !is_wp_error($error)
		|| empty(Codi_Mail_SES_PHPMailer::$last_provider_error)
	) {
		return;
	}

	$data = $error->get_error_data();
	if(!is_array($data)) {
		$data = [];
	}

	$data['codi_mail'] = Codi_Mail_SES_PHPMailer::$last_provider_error;
	$error->add_data($data, $error->get_error_code());
	Codi_Mail_SES_PHPMailer::$last_provider_error = [];
}

add_filter('pre_wp_mail', 'codi_mail_ses_prepare_phpmailer', PHP_INT_MAX, 2);
add_action('phpmailer_init', 'codi_mail_ses_select_transport', PHP_INT_MAX);
add_action('wp_mail_failed', 'codi_mail_ses_enrich_failure', 1);
