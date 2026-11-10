<?php

namespace CodiMail\Ses;

class Client {
	private $accessKey;
	private $secretKey;
	private $host;
	private $request = null;

	public function __construct($accessKey, $secretKey, $host) {
		$this->accessKey = (string)$accessKey;
		$this->secretKey = (string)$secretKey;
		$this->host = (string)$host;
	}

	public function getAccessKey() {
		return $this->accessKey;
	}

	public function getSecretKey() {
		return $this->secretKey;
	}

	public function getHost() {
		return $this->host;
	}

	public function sendRawEmail($rawMessage, array $destination = [], $fromEmail = null) {
		$payload = [
			'Content' => [
				'Raw' => [
					'Data' => base64_encode((string)$rawMessage),
				],
			],
		];

		$destination = array_filter($destination, function($addresses) {
			return !empty($addresses);
		});

		if($destination) {
			$payload['Destination'] = $destination;
		}

		if($fromEmail !== null && $fromEmail !== '') {
			$payload['FromEmailAddress'] = (string)$fromEmail;
		}

		$request = $this->getRequest('POST');
		$request->setPath('/v2/email/outbound-emails');
		$request->setJsonPayload($payload);

		$response = $request->getResponse();
		if($response->error !== false) {
			return $response;
		}

		return [
			'MessageId' => isset($response->body->MessageId) ? (string)$response->body->MessageId : '',
			'RequestId' => isset($response->request_id) ? (string)$response->request_id : '',
		];
	}

	public function setRequest(?Request $request = null) {
		if($request !== null) {
			$request->setClient($this);
		}

		$this->request = $request;
		return $this;
	}

	public function getRequest($verb) {
		if($this->request === null) {
			$this->request = new Request($this, $verb);
		} else {
			$this->request->setVerb($verb);
		}

		return $this->request;
	}
}
