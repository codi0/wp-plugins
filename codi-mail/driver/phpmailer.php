<?php

defined('ABSPATH') || exit;

if(!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}

class Codi_Mail_PHPMailer extends PHPMailer\PHPMailer\PHPMailer {
	private $codiFileError = '';
	private $codiTransportResult = [];

	public function codiResetSendState() {
		$this->codiFileError = '';
		$this->codiTransportResult = [];
	}

	public function codiSetTransportResult($provider, $providerMessageId = '', $messageId = null) {
		$this->codiTransportResult = [
			'provider' => (string)$provider,
			'provider_message_id' => (string)$providerMessageId,
		];
		if($messageId !== null) {
			$this->codiTransportResult['message_id'] = (string)$messageId;
		}
	}

	public function codiTransportResult() {
		return $this->codiTransportResult;
	}

	public function addAttachment(
		$path,
		$name = '',
		$encoding = self::ENCODING_BASE64,
		$type = '',
		$disposition = 'attachment'
	) {
		try {
			$result = parent::addAttachment($path, $name, $encoding, $type, $disposition);
			if(!$result) {
				$this->codiRememberFileError('Email attachment could not be added.', $path);
			}
			return $result;
		} catch(PHPMailer\PHPMailer\Exception $e) {
			$this->codiRememberFileError($e->getMessage(), $path);
			throw $e;
		}
	}

	public function addEmbeddedImage(
		$path,
		$cid,
		$name = '',
		$encoding = self::ENCODING_BASE64,
		$type = '',
		$disposition = 'inline'
	) {
		try {
			$result = parent::addEmbeddedImage($path, $cid, $name, $encoding, $type, $disposition);
			if(!$result) {
				$this->codiRememberFileError('Embedded email file could not be added.', $path);
			}
			return $result;
		} catch(PHPMailer\PHPMailer\Exception $e) {
			$this->codiRememberFileError($e->getMessage(), $path);
			throw $e;
		}
	}

	protected function smtpSend($header, $body) {
		if(function_exists('codi_mail_smtp_before_send')) {
			codi_mail_smtp_before_send($this);
		}

		$result = parent::smtpSend($header, $body);
		if($result) {
			$this->codiSetTransportResult('smtp');
		}

		return $result;
	}

	public function preSend() {
		if($this->codiFileError !== '') {
			throw new PHPMailer\PHPMailer\Exception($this->codiFileError);
		}

		return parent::preSend();
	}

	private function codiRememberFileError($message, $path) {
		if($this->codiFileError === '') {
			$this->codiFileError = $message !== ''
				? (string)$message
				: 'Email file could not be read: ' . (string)$path;
		}
	}
}
