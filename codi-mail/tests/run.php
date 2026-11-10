<?php

namespace PHPMailer\PHPMailer {
	class Exception extends \Exception {}

	class SMTP {
		public $connected = false;
		public $Timelimit = 300;

		public function connected() {
			return $this->connected;
		}
	}

	class PHPMailer {
		const ENCODING_BASE64 = 'base64';

		public static $validator;
		public $Mailer = 'mail';
		public $Host = '';
		public $Port = 587;
		public $Username = '';
		public $Password = '';
		public $SMTPSecure = '';
		public $SMTPAutoTLS = true;
		public $SMTPAuth = false;
		public $SMTPKeepAlive = false;
		public $SMTPOptions = [];
		public $AuthType = '';
		public $Timeout = 300;
		public $From = 'sender@example.test';
		public $seenMailer = '';
		public $lastConnectedHost = '';
		public $closeCount = 0;
		protected $smtp;

		public function __construct($exceptions = null) {
			$this->smtp = new SMTP();
		}

		public function addAttachment($path, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'attachment') {
			if($path === 'bad-attachment') {
				throw new Exception('attachment unavailable');
			}
			return true;
		}

		public function addEmbeddedImage($path, $cid, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'inline') {
			if($path === 'bad-embed') {
				throw new Exception('embed unavailable');
			}
			return true;
		}

		public function preSend() {
			$this->seenMailer = $this->Mailer;
			return true;
		}

		public function isSMTP() {
			$this->Mailer = 'smtp';
		}

		public function getSMTPInstance() {
			return $this->smtp;
		}

		public function smtpClose() {
			$this->closeCount++;
			$this->smtp->connected = false;
		}

		protected function smtpSend($header, $body) {
			$this->smtp->connected = true;
			$this->lastConnectedHost = $this->Host;
			return true;
		}

		public function sendSmtpForInvariant() {
			return $this->smtpSend('', '');
		}

		public function getSentMIMEMessage() {
			return 'MIME';
		}

		public function getToAddresses() {
			return [ [ 'to@example.test', '' ] ];
		}

		public function getCcAddresses() {
			return [];
		}

		public function getBccAddresses() {
			return [ [ 'hidden@example.test', '' ] ];
		}
	}
}

namespace {
	define('ABSPATH', dirname(__DIR__) . '/');
	define('WPINC', 'wp-includes');
	define('CODI_MAIL_PLUGIN_FILE', dirname(__DIR__) . '/codi-mail.php');
	define('CODI_MAIL_PLUGIN_NAME', 'codi-mail');

	$GLOBALS['codi_mail_test_opts'] = [
		'type' => 'smtp',
		'host' => 'smtp-a.example.test',
		'port' => 587,
		'protocol' => 'tls',
		'username' => 'user',
		'password' => 'secret',
		'from' => 'sender@example.test',
	];

	function codi_mail_opts() {
		return $GLOBALS['codi_mail_test_opts'];
	}

	function is_email($email) {
		return true;
	}

	function wp_strip_all_tags($text) {
		return strip_tags((string)$text);
	}

	function plugin_basename($file) {
		return 'codi-mail/codi-mail.php';
	}

	function apply_filters($tag, $value) {
		return $value;
	}

	function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
	function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
	function do_action($tag) {}
	function is_wp_error($value) {
		return $value instanceof WP_Error;
	}

	class WP_Error {
		public function __construct($code = '', $message = '', $data = null) {}
	}

	function codi_mail_test_assert($condition, $message) {
		if(!$condition) {
			fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
			exit(1);
		}
	}

	require dirname(__DIR__) . '/driver/smtp.php';

	codi_mail_test_assert(
		!class_exists('Codi_Mail_PHPMailer', false),
		'SMTP driver must not load PHPMailer before the first mail send.'
	);

	global $phpmailer;
	$phpmailer = null;

	codi_mail_test_assert(
		codi_mail_smtp_prepare_phpmailer(null, []) === null,
		'Configured SMTP must allow WordPress to continue sending.'
	);
	codi_mail_test_assert(
		$phpmailer instanceof Codi_Mail_PHPMailer,
		'First SMTP send must lazily install the shared PHPMailer adapter.'
	);

	codi_mail_smtp_init_phpmailer($phpmailer);
	$phpmailer->sendSmtpForInvariant();
	codi_mail_test_assert(
		$phpmailer->lastConnectedHost === 'smtp-a.example.test',
		'Initial SMTP send must use the configured relay.'
	);

	$phpmailer->Host = 'smtp-b.example.test';
	$phpmailer->sendSmtpForInvariant();
	codi_mail_test_assert(
		$phpmailer->closeCount === 1 && $phpmailer->lastConnectedHost === 'smtp-b.example.test',
		'Connected SMTP socket must be replaced when final send-time identity changes.'
	);

	try {
		$phpmailer->addAttachment('bad-attachment');
	} catch(\PHPMailer\PHPMailer\Exception $e) {
	}
	try {
		$phpmailer->preSend();
		codi_mail_test_assert(false, 'Failed attachment must abort the final send.');
	} catch(\PHPMailer\PHPMailer\Exception $e) {
		codi_mail_test_assert(
			strpos($e->getMessage(), 'attachment unavailable') !== false,
			'Attachment failure must retain the PHPMailer error.'
		);
	}

	require dirname(__DIR__) . '/driver/ses.php';

	codi_mail_test_assert(
		!class_exists('Codi_Mail_SES_PHPMailer', false)
		&& !class_exists('CodiMail\\Ses\\Client', false)
		&& !class_exists('CodiMail\\Ses\\Request', false),
		'SES driver must not eagerly load PHPMailer or HTTP client classes.'
	);

	codi_mail_ses_load_phpmailer();
	codi_mail_test_assert(
		class_exists('Codi_Mail_SES_PHPMailer', false),
		'SES PHPMailer adapter must load on demand.'
	);
	codi_mail_test_assert(
		!class_exists('CodiMail\\Ses\\Client', false)
		&& !class_exists('CodiMail\\Ses\\Request', false),
		'Loading the SES mailer must not load the HTTP client.'
	);

	codi_mail_ses_load_client();
	codi_mail_test_assert(
		class_exists('CodiMail\\Ses\\Client', false)
		&& class_exists('CodiMail\\Ses\\Request', false),
		'SES HTTP classes must load together on demand.'
	);

	$sesMailer = new Codi_Mail_SES_PHPMailer(true);
	$sesMailer->Mailer = 'codiSes';
	codi_mail_test_assert($sesMailer->preSend() === true, 'SES MIME pre-send must succeed.');
	codi_mail_test_assert(
		$sesMailer->seenMailer === 'smtp' && $sesMailer->Mailer === 'codiSes',
		'SES must build SMTP-style MIME and restore its custom transport.'
	);

	require dirname(__DIR__) . '/admin.php';

	codi_mail_test_assert(
		codi_mail_admin_test_headers('Plain text only.') === [],
		'Admin test must keep plain-text messages as text/plain.'
	);
	codi_mail_test_assert(
		codi_mail_admin_test_headers('<p>HTML message</p>') === [ 'Content-Type: text/html; charset=UTF-8' ],
		'Admin test must mark messages containing HTML tags as text/html.'
	);

	echo "PASS\n";
}
