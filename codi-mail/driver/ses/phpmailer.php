<?php

defined('ABSPATH') || exit;

class Codi_Mail_SES_PHPMailer extends Codi_Mail_PHPMailer {
	public static $last_provider_error = [];

	public function preSend() {
		if($this->Mailer !== 'codiSes') {
			return parent::preSend();
		}

		$this->Mailer = 'smtp';

		try {
			return parent::preSend();
		} finally {
			$this->Mailer = 'codiSes';
		}
	}

	public function codiSesSend($header, $body) {
		self::$last_provider_error = [];

		try {
			$destination = array_filter([
				'ToAddresses' => $this->codiMailAddresses($this->getToAddresses()),
				'CcAddresses' => $this->codiMailAddresses($this->getCcAddresses()),
				'BccAddresses' => $this->codiMailAddresses($this->getBccAddresses()),
			]);

			$response = codi_mail_ses_service()->sendRawEmail(
				$this->getSentMIMEMessage(),
				$destination,
				$this->From
			);

			if(is_array($response) && !empty($response['MessageId'])) {
				return true;
			}

			self::$last_provider_error = codi_mail_ses_error_data($response);
			$code = !empty(self::$last_provider_error['http_status'])
				? (int)self::$last_provider_error['http_status']
				: 0;

			throw new PHPMailer\PHPMailer\Exception(codi_mail_ses_error_message($response), $code);
		} catch(PHPMailer\PHPMailer\Exception $e) {
			throw $e;
		} catch(Throwable $e) {
			self::$last_provider_error = [
				'provider' => 'ses',
				'exception_class' => get_class($e),
				'exception_code' => $e->getCode(),
			];

			throw new PHPMailer\PHPMailer\Exception($e->getMessage(), (int)$e->getCode());
		}
	}

	private function codiMailAddresses($addresses) {
		$result = [];

		foreach((array)$addresses as $address) {
			if(is_array($address) && !empty($address[0])) {
				$result[] = (string)$address[0];
			}
		}

		return $result;
	}
}
