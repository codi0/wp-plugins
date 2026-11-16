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
		public $MessageID = '<mime@example.test>';
		public $seenMailer = '';
		public $lastConnectedHost = '';
		public $closeCount = 0;
		public $customHeaders = [];
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

		public function getLastMessageID() {
			return $this->MessageID;
		}

		public function getCustomHeaders() {
			return $this->customHeaders;
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

	$GLOBALS['codi_mail_test_opts'] = [
		'type' => 'smtp',
		'host' => 'smtp-a.example.test',
		'port' => 587,
		'protocol' => 'tls',
		'username' => 'user',
		'password' => 'secret',
		'from' => 'sender@example.test',
	];
	$GLOBALS['codi_mail_test_options'] = [];
	$GLOBALS['codi_mail_test_actions'] = [];
	$GLOBALS['codi_mail_test_scheduled'] = [];

	function get_bloginfo($key) {
		if($key === 'admin_email') {
			return 'sender@example.test';
		}
		if($key === 'name') {
			return 'Example Site';
		}
		return '';
	}

	function sanitize_key($value) {
		return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string)$value));
	}

	function sanitize_text_field($value) {
		return (string)$value;
	}

	function absint($value) {
		return abs((int)$value);
	}

	function sanitize_email($value) {
		return (string)$value;
	}

	function wp_generate_uuid4() {
		static $counter = 0;
		$counter++;
		return sprintf('00000000-0000-4000-8000-%012d', $counter);
	}

	function get_option($name, $default = false) {
		if($name === 'codi_mail_opts') {
			return $GLOBALS['codi_mail_test_opts'];
		}
		return array_key_exists($name, $GLOBALS['codi_mail_test_options'])
			? $GLOBALS['codi_mail_test_options'][$name]
			: $default;
	}

	function update_option($name, $value, $autoload = null) {
		if($name === 'codi_mail_opts') {
			$GLOBALS['codi_mail_test_opts'] = $value;
		} else {
			$GLOBALS['codi_mail_test_options'][$name] = $value;
		}
		return true;
	}

	function delete_option($name) {
		unset($GLOBALS['codi_mail_test_options'][$name]);
		return true;
	}

	function is_admin() {
		return false;
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
	function do_action($tag, ...$args) {
		$GLOBALS['codi_mail_test_actions'][] = [ $tag, $args ];
	}
	function register_activation_hook($file, $callback) {}
	function register_deactivation_hook($file, $callback) {}
	function wp_next_scheduled($hook) {
		return $GLOBALS['codi_mail_test_scheduled'][$hook] ?? false;
	}
	function wp_schedule_event($timestamp, $recurrence, $hook) {
		$GLOBALS['codi_mail_test_scheduled'][$hook] = $timestamp;
		return true;
	}
	function wp_unschedule_event($timestamp, $hook) {
		unset($GLOBALS['codi_mail_test_scheduled'][$hook]);
		return true;
	}
	function current_time($type, $gmt = false) {
		return gmdate('Y-m-d H:i:s');
	}
	function is_wp_error($value) {
		return $value instanceof WP_Error;
	}

	class WP_Error {
		private $message;
		public function __construct($code = '', $message = '', $data = null) {
			$this->message = (string)$message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	function codi_mail_test_assert($condition, $message) {
		if(!$condition) {
			fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
			exit(1);
		}
	}

	require dirname(__DIR__) . '/codi-mail.php';

	$defaultFrom = codi_mail_default_from([
		'headers' => [],
	]);
	codi_mail_test_assert(
		$defaultFrom['headers'] === [ 'From: Example Site <sender@example.test>' ],
		'Default admin From must combine the site name with the admin email.'
	);

	$explicitAdminFrom = codi_mail_default_from([
		'headers' => [ 'From: sender@example.test' ],
	]);
	codi_mail_test_assert(
		$explicitAdminFrom['headers'] === [ 'From: Example Site <sender@example.test>' ],
		'Explicit admin From address must receive the site name.'
	);

	$namedAdminFrom = codi_mail_default_from([
		'headers' => [ 'From: WordPress <sender@example.test>' ],
	]);
	codi_mail_test_assert(
		$namedAdminFrom['headers'] === [ 'From: Example Site <sender@example.test>' ],
		'Admin From address must use the site name even when another name was supplied.'
	);

	$explicitFrom = codi_mail_default_from([
		'headers' => [ 'From: Custom Sender <custom@example.test>' ],
	]);
	codi_mail_test_assert(
		$explicitFrom['headers'] === [ 'From: Custom Sender <custom@example.test>' ],
		'Explicit From headers must remain untouched.'
	);

	$GLOBALS['codi_mail_test_opts']['from'] = 'custom@example.test';
	$customDefaultFrom = codi_mail_default_from([
		'headers' => [],
	]);
	codi_mail_test_assert(
		$customDefaultFrom['headers'] === [ 'From: custom@example.test' ],
		'Configured custom default From must remain custom and must not inherit the site name.'
	);
	$GLOBALS['codi_mail_test_opts']['from'] = 'sender@example.test';

	$plainMail = codi_mail_html_content_type([
		'message' => 'Plain text only.',
		'headers' => [],
	]);
	codi_mail_test_assert(
		$plainMail['headers'] === [],
		'Plain-text messages must keep their existing content type.'
	);

	$htmlMail = codi_mail_html_content_type([
		'message' => '<p>HTML message</p>',
		'headers' => [],
	]);
	codi_mail_test_assert(
		$htmlMail['headers'] === [ 'Content-Type: text/html; charset=UTF-8' ],
		'HTML messages without a content type must be marked as text/html.'
	);

	$explicitContentType = codi_mail_html_content_type([
		'message' => '<p>HTML message</p>',
		'headers' => [ 'Content-Type: text/plain; charset=UTF-8' ],
	]);
	codi_mail_test_assert(
		$explicitContentType['headers'] === [ 'Content-Type: text/plain; charset=UTF-8' ],
		'Explicit content type headers must bypass HTML auto-detection.'
	);

	$stringHeaders = codi_mail_html_content_type([
		'message' => '<strong>HTML message</strong>',
		'headers' => 'X-Test: value',
	]);
	codi_mail_test_assert(
		$stringHeaders['headers'] === "X-Test: value\r\nContent-Type: text/html; charset=UTF-8",
		'HTML content type detection must preserve string header format.'
	);

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

	$GLOBALS['codi_mail_test_actions'] = [];
	$phpmailer->customHeaders = [ [ 'X-Application-Correlation', 'opaque-value' ] ];
	codi_mail_emit_sent([
		'to' => [ 'to@example.test' ],
		'headers' => [],
	]);
	$sentEvents = array_values(array_filter($GLOBALS['codi_mail_test_actions'], function($event) {
		return $event[0] === 'codi_mail/sent';
	}));
	codi_mail_test_assert(count($sentEvents) === 1, 'Successful Codi transport must emit one generic sent event.');
	$sent = $sentEvents[0][1][0];
	codi_mail_test_assert(
		$sent['provider'] === 'smtp'
		&& $sent['message_id'] === '<mime@example.test>'
		&& $sent['headers'] === [ 'X-Application-Correlation: opaque-value' ]
		&& !array_key_exists('message', $sent),
		'Sent event must expose transport identity and actual PHPMailer custom headers without duplicating message content.'
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

	$capturingRequest = new class extends \CodiMail\Ses\Request {
		public $payload = null;
		public $path = '';
		public function setPath($path) {
			$this->path = (string)$path;
			return $this;
		}
		public function setJsonPayload($payload) {
			$this->payload = $payload;
			return $this;
		}
		public function getResponse() {
			return (object)[
				'error' => false,
				'body' => (object)[ 'MessageId' => 'raw-message-id' ],
				'request_id' => 'request-id',
			];
		}
	};
	$rawClient = new \CodiMail\Ses\Client('access-key', 'secret-key', 'email.eu-west-2.amazonaws.com');
	$rawClient->setRequest($capturingRequest);
	$rawMessage = "From: Example Site <sender@example.test>\r\nTo: to@example.test\r\nSubject: Raw test\r\n\r\nBody";
	$rawResult = $rawClient->sendRawEmail($rawMessage, [ 'ToAddresses' => [ 'to@example.test' ] ]);
	codi_mail_test_assert(
		$rawResult['MessageId'] === 'raw-message-id'
		&& $capturingRequest->path === '/v2/email/outbound-emails'
		&& !array_key_exists('FromEmailAddress', $capturingRequest->payload)
		&& base64_decode($capturingRequest->payload['Content']['Raw']['Data']) === $rawMessage,
		'SES raw send must preserve the MIME From header and must not override it with a bare FromEmailAddress.'
	);

	$sesMailer = new Codi_Mail_SES_PHPMailer(true);
	$sesMailer->Mailer = 'codiSes';
	codi_mail_test_assert($sesMailer->preSend() === true, 'SES MIME pre-send must succeed.');
	codi_mail_test_assert(
		$sesMailer->seenMailer === 'smtp' && $sesMailer->Mailer === 'codiSes',
		'SES must build SMTP-style MIME and restore its custom transport.'
	);

	$sesMailer->codiSetTransportResult('ses', 'provider-id', '');
	$sesTransport = $sesMailer->codiTransportResult();
	codi_mail_test_assert(
		isset($sesTransport['provider_message_id'])
		&& $sesTransport['provider_message_id'] === 'provider-id'
		&& array_key_exists('message_id', $sesTransport)
		&& $sesTransport['message_id'] === '',
		'SES transport must mark the pre-send PHPMailer Message-ID as unusable after provider overwrite.'
	);

	$GLOBALS['phpmailer'] = $sesMailer;
	$GLOBALS['codi_mail_test_actions'] = [];
	codi_mail_emit_sent([
		'to' => [ 'to@example.test' ],
		'headers' => [],
	]);
	$sesSentEvents = array_values(array_filter($GLOBALS['codi_mail_test_actions'], function($event) {
		return $event[0] === 'codi_mail/sent';
	}));
	codi_mail_test_assert(
		count($sesSentEvents) === 1
		&& $sesSentEvents[0][1][0]['message_id'] === ''
		&& $sesSentEvents[0][1][0]['provider_message_id'] === 'provider-id',
		'SES sent event must retain provider identity while refusing the overwritten PHPMailer Message-ID.'
	);
	$GLOBALS['phpmailer'] = $phpmailer;

	require dirname(__DIR__) . '/inbound/graph.php';

	$threadHeaders = codi_mail_graph_headers([
		[ 'name' => 'In-Reply-To', 'value' => '<parent@example.test>' ],
		[ 'name' => 'References', 'value' => '<root@example.test> <parent@example.test>' ],
	]);
	codi_mail_test_assert(
		codi_mail_graph_header_first($threadHeaders, 'in-reply-to') === '<parent@example.test>'
		&& codi_mail_graph_message_ids(codi_mail_graph_header_first($threadHeaders, 'references')) === [ '<root@example.test>', '<parent@example.test>' ],
		'Thread identifiers must survive Graph normalisation exactly and in order.'
	);

	codi_mail_test_assert(
		codi_mail_graph_classification([ 'auto-submitted' => [ 'auto-replied' ] ], 'person@example.test', 'Re: Hello') === 'auto_reply'
		&& codi_mail_graph_classification([ 'return-path' => [ '<>' ] ], 'mailer-daemon@example.test', 'Delivery failed') === 'bounce'
		&& codi_mail_graph_classification([], 'person@example.test', 'Re: Hello') === 'normal',
		'Inbound classification must separate human replies from automatic replies and bounces.'
	);

	$accounts = codi_mail_inbound_accounts([
		'accountone' => [
			'label' => 'First mailbox',
			'enabled' => true,
			'provider' => 'graph',
			'tenant_id' => 'tenant-a',
			'client_id' => 'client-a',
			'client_secret' => 'secret-a',
			'mailbox' => 'FIRST@EXAMPLE.TEST',
			'page_size' => 25,
		],
		'accounttwo' => [
			'label' => 'Second mailbox',
			'enabled' => true,
			'provider' => 'graph',
			'tenant_id' => 'tenant-b',
			'client_id' => 'client-b',
			'client_secret' => 'secret-b',
			'mailbox' => 'second@example.test',
			'page_size' => 50,
		],
	]);
	codi_mail_test_assert(
		count($accounts) === 2
		&& $accounts['accountone']['mailbox'] === 'first@example.test'
		&& $accounts['accounttwo']['mailbox'] === 'second@example.test',
		'Inbound account configuration must support multiple independently addressed mailboxes.'
	);
	$preserved = codi_mail_inbound_sanitize_account([
		'client_secret' => '',
		'mailbox' => 'first@example.test',
	], $accounts['accountone'], 'accountone');
	codi_mail_test_assert(
		$preserved['client_secret'] === 'secret-a',
		'Blank inbound secrets must retain the existing account credential.'
	);

	update_option('codi_mail_graph_delta_accountone', 'stale-cursor', false);
	codi_mail_inbound_health('accountone', [
		'last_success_at' => '2026-01-01T00:00:00+00:00',
		'consecutive_failures' => 2,
	]);
	$accounts['accountone']['mailbox'] = 'replacement@example.test';
	$accounts = codi_mail_inbound_accounts($accounts);
	codi_mail_test_assert(
		get_option('codi_mail_graph_delta_accountone', '') === ''
		&& codi_mail_inbound_health('accountone') === [],
		'Changing an inbound mailbox must reset provider cursor and health state for that stable account id.'
	);

	$GLOBALS['codi_mail_test_actions'] = [];
	$inboundOne = [
		'inbound_account_id' => 'accountone',
		'inbound_account_label' => 'First mailbox',
		'mailbox' => 'FIRST@EXAMPLE.TEST',
		'provider' => 'graph',
		'provider_message_id' => 'provider-1',
		'message_id' => '',
		'in_reply_to' => '<outbound@example.test>',
		'references' => [ '<root@example.test>', '<outbound@example.test>' ],
	];
	codi_mail_test_assert(codi_mail_inbound_dispatch($inboundOne) === true, 'Valid provider message must be dispatched.');

	$inboundTwo = $inboundOne;
	$inboundTwo['provider_message_id'] = 'provider-2';
	codi_mail_test_assert(codi_mail_inbound_dispatch($inboundTwo) === true, 'Missing Internet Message-ID must not collapse distinct provider messages.');
	$inboundEvents = array_values(array_filter($GLOBALS['codi_mail_test_actions'], function($event) {
		return $event[0] === 'codi_mail_inbound_message';
	}));
	codi_mail_test_assert(count($inboundEvents) === 2, 'Distinct provider messages must each reach consumers.');
	codi_mail_test_assert(
		$inboundEvents[0][1][0]['mailbox'] === 'first@example.test'
		&& $inboundEvents[0][1][0]['inbound_account_id'] === 'accountone'
		&& $inboundEvents[0][1][0]['in_reply_to'] === '<outbound@example.test>'
		&& $inboundEvents[0][1][0]['references'] === [ '<root@example.test>', '<outbound@example.test>' ],
		'Inbound dispatch must preserve exact thread headers.'
	);

	echo "PASS\n";
}
