<?php

defined('ABSPATH') || exit;

function codi_mail_smtp_load_phpmailer() {
	if(!class_exists('Codi_Mail_PHPMailer', false)) {
		require_once __DIR__ . '/phpmailer.php';
	}
}

function codi_mail_smtp_connection_fingerprint($new = null) {
	static $fingerprint = '';

	if($new !== null) {
		$fingerprint = (string)$new;
	}

	return $fingerprint;
}

function codi_mail_smtp_connection_key($phpmailer) {
	$smtpOptions = isset($phpmailer->SMTPOptions)
		? json_encode((array)$phpmailer->SMTPOptions, JSON_PARTIAL_OUTPUT_ON_ERROR)
		: '';

	return hash('sha256', implode("\0", [
		(string)$phpmailer->Host,
		(string)(int)$phpmailer->Port,
		(string)$phpmailer->SMTPSecure,
		$phpmailer->SMTPAutoTLS ? '1' : '0',
		$phpmailer->SMTPAuth ? '1' : '0',
		(string)$phpmailer->Username,
		(string)$phpmailer->Password,
		isset($phpmailer->AuthType) ? (string)$phpmailer->AuthType : '',
		$smtpOptions === false ? '' : $smtpOptions,
	]));
}

function codi_mail_smtp_close_connection($mailer = null) {
	codi_mail_smtp_connection_fingerprint('');

	if($mailer === null) {
		global $phpmailer;
		$mailer = $phpmailer;
	}

	if(
		!class_exists('Codi_Mail_PHPMailer', false)
		|| !($mailer instanceof Codi_Mail_PHPMailer)
	) {
		return;
	}

	$mailer->SMTPKeepAlive = false;

	if(method_exists($mailer, 'smtpClose')) {
		try {
			$mailer->smtpClose();
		} catch(Throwable $e) {
			// Closing a stale or already-dead socket must not break the request.
		}
	}
}

function codi_mail_smtp_close_on_failure($error) {
	codi_mail_smtp_close_connection();
}

function codi_mail_smtp_prepare_phpmailer($return, $atts) {
	if($return !== null) {
		codi_mail_smtp_close_connection();
		return $return;
	}

	$opts = codi_mail_opts();
	if($opts['type'] !== 'smtp') {
		codi_mail_smtp_close_connection();
		return $return;
	}

	if($opts['host'] === '') {
		$data = is_array($atts) ? $atts : [];
		$data['codi_mail'] = [ 'provider' => 'smtp' ];
		do_action(
			'wp_mail_failed',
			new WP_Error('wp_mail_failed', 'Codi Mail SMTP host is not configured.', $data)
		);
		return false;
	}

	codi_mail_smtp_load_phpmailer();

	global $phpmailer;

	if(!($phpmailer instanceof Codi_Mail_PHPMailer)) {
		$phpmailer = new Codi_Mail_PHPMailer(true);
		$phpmailer::$validator = static function($email) {
			return (bool)is_email($email);
		};
	}

	$phpmailer->codiResetSendState();
	return $return;
}

function codi_mail_smtp_init_phpmailer($phpmailer) {
	$opts = codi_mail_opts();

	if($opts['type'] !== 'smtp' || $opts['host'] === '') {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->SMTPAuth = ($opts['username'] !== '' || $opts['password'] !== '');
	$phpmailer->Host = $opts['host'];
	$phpmailer->Port = (int)$opts['port'];
	$phpmailer->Username = $opts['username'];
	$phpmailer->Password = $opts['password'];
	$phpmailer->SMTPSecure = $opts['protocol'];
	$phpmailer->SMTPAutoTLS = ($opts['protocol'] === 'tls');

	$timeout = (int)apply_filters('codi_mail_smtp_timeout', 30, $phpmailer, $opts);
	$timeout = max(1, $timeout);
	$phpmailer->Timeout = $timeout;

	if(method_exists($phpmailer, 'getSMTPInstance')) {
		$phpmailer->getSMTPInstance()->Timelimit = $timeout;
	}
}

function codi_mail_smtp_before_send($phpmailer) {
	if(
		!($phpmailer instanceof Codi_Mail_PHPMailer)
		|| $phpmailer->Mailer !== 'smtp'
	) {
		return;
	}

	$key = codi_mail_smtp_connection_key($phpmailer);
	$activeKey = codi_mail_smtp_connection_fingerprint();
	$smtp = method_exists($phpmailer, 'getSMTPInstance')
		? $phpmailer->getSMTPInstance()
		: null;
	$connected = $smtp && method_exists($smtp, 'connected') && $smtp->connected();

	if($connected && ($activeKey === '' || $activeKey !== $key)) {
		codi_mail_smtp_close_connection($phpmailer);
	}

	codi_mail_smtp_connection_fingerprint($key);
	$phpmailer->SMTPKeepAlive = true;
}

add_filter('pre_wp_mail', 'codi_mail_smtp_prepare_phpmailer', PHP_INT_MAX, 2);
add_action('phpmailer_init', 'codi_mail_smtp_init_phpmailer', PHP_INT_MAX);
add_action('wp_mail_failed', 'codi_mail_smtp_close_on_failure', PHP_INT_MAX);
register_shutdown_function('codi_mail_smtp_close_connection');
