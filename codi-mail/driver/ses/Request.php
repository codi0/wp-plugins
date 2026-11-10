<?php

namespace CodiMail\Ses;

use DateTime;
use DateTimeZone;
use RuntimeException;

class Request {
	private $client;
	private $verb;
	private $parameters = [];
	private $path = '/';
	private $jsonPayload = null;
	private $curlHandler = null;
	private $response;

	public static $curlOptions = [];

	public function __construct(?Client $client = null, $verb = 'GET') {
		$this->client = $client;
		$this->verb = $verb;
		$this->resetResponse();
	}

	public function setClient(Client $client) {
		$this->client = $client;
		return $this;
	}

	public function setVerb($verb) {
		$this->verb = strtoupper((string)$verb);
		return $this;
	}

	public function setPath($path) {
		$this->path = (string)$path;
		return $this;
	}

	public function setJsonPayload($payload) {
		$this->jsonPayload = $payload;
		return $this;
	}

	public function setParameter($key, $value, $replace = true) {
		if(!$replace && isset($this->parameters[$key])) {
			$values = (array)$this->parameters[$key];
			$values[] = $value;
			$this->parameters[$key] = $values;
		} else {
			$this->parameters[$key] = $value;
		}

		return $this;
	}

	public function getResponse() {
		if(!$this->client) {
			throw new RuntimeException('Amazon SES request has no client.');
		}

		$this->resetResponse();

		$query = implode('&', $this->getParametersEncoded());
		$payload = '';

		if($this->jsonPayload !== null) {
			$payload = json_encode($this->jsonPayload);
			if($payload === false) {
				throw new RuntimeException('Amazon SES request payload could not be encoded.');
			}
		}

		$url = 'https://' . $this->client->getHost() . $this->path;
		if(($this->verb === 'GET' || $this->verb === 'DELETE') && $query !== '') {
			$url .= '?' . $query;
		}

		$signingPayload = $this->verb === 'POST' ? $payload : '';
		$headers = $this->getHeaders($signingPayload, $query);
		$curl = $this->getCurlHandler();

		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
		curl_setopt($curl, CURLOPT_URL, $url);

		if($this->verb === 'POST') {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
			$headers[] = 'Content-Type: application/json';
		} else {
			curl_setopt($curl, CURLOPT_POSTFIELDS, '');
		}

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		if(curl_exec($curl)) {
			$this->response->code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		} else {
			$this->response->error = [
				'curl' => true,
				'code' => curl_errno($curl),
				'message' => curl_error($curl),
			];
		}

		$this->response->request_id = $this->response->headers['x-amzn-requestid']
			?? $this->response->headers['x-amzn-request-id']
			?? '';

		if($this->response->error === false) {
			$this->parseResponse();
		}

		$response = $this->response;
		$this->parameters = [];
		$this->path = '/';
		$this->jsonPayload = null;
		$this->resetResponse();

		return $response;
	}

	private function getCurlHandler() {
		if($this->curlHandler) {
			return $this->curlHandler;
		}

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, 'Codi-Mail/SES');
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($curl, CURLOPT_WRITEFUNCTION, [ $this, 'responseWriteCallback' ]);
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, [ $this, 'responseHeaderCallback' ]);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);

		foreach(self::$curlOptions as $option => $value) {
			curl_setopt($curl, $option, $value);
		}

		$this->curlHandler = $curl;
		return $this->curlHandler;
	}

	private function parseResponse() {
		$success = in_array($this->response->code, [ 200, 201, 202, 204 ], true);
		$body = null;

		if($this->response->body !== '') {
			$body = json_decode($this->response->body);
		}

		if($success) {
			$this->response->body = $body ?: (object)[];
			return;
		}

		$errorType = $this->response->headers['x-amzn-errortype'] ?? '';
		if($errorType !== '') {
			$errorType = explode(':', $errorType, 2)[0];
		} elseif(is_object($body) && isset($body->__type)) {
			$errorType = (string)$body->__type;
		}

		$message = '';
		if(is_object($body)) {
			$message = isset($body->message)
				? (string)$body->message
				: (isset($body->Message) ? (string)$body->Message : '');
		}

		$this->response->error = [
			'curl' => false,
			'Error' => [
				'Type' => '',
				'Code' => $errorType !== '' ? $errorType : (string)$this->response->code,
				'Message' => $message,
			],
			'RequestId' => (string)$this->response->request_id,
		];
		$this->response->body = null;
	}

	private function getParametersEncoded() {
		$params = [];

		foreach($this->parameters as $name => $value) {
			foreach((array)$value as $item) {
				$params[] = $name . '=' . rawurlencode((string)$item);
			}
		}

		sort($params, SORT_STRING);
		return $params;
	}

	private function getHeaders($payload, $query = '') {
		$date = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

		return [
			'X-Amz-Date: ' . $date,
			'Host: ' . $this->client->getHost(),
			'Authorization: ' . $this->getAuthHeader($date, $payload, $query),
		];
	}

	private function getAuthHeader($dateTime, $payload, $query) {
		$host = strtolower($this->client->getHost());
		if(!preg_match('/^email\\.([a-z0-9-]+)\\.amazonaws\\.(?:com(?:\\.cn)?|eu)$/', $host, $matches)) {
			throw new RuntimeException('Invalid Amazon SES endpoint host.');
		}

		$date = substr($dateTime, 0, 8);
		$region = $matches[1];
		$algorithm = 'AWS4-HMAC-SHA256';
		$canonicalQuery = $this->verb === 'POST' ? '' : $query;
		$canonicalHeaders = 'host:' . $this->client->getHost() . "\n" . 'x-amz-date:' . $dateTime . "\n";
		$signedHeaders = 'host;x-amz-date';

		$canonicalRequest = implode("\n", [
			$this->verb,
			$this->path,
			$canonicalQuery,
			$canonicalHeaders,
			$signedHeaders,
			hash('sha256', $payload),
		]);

		$scope = $date . '/' . $region . '/ses/aws4_request';
		$stringToSign = implode("\n", [
			$algorithm,
			$dateTime,
			$scope,
			hash('sha256', $canonicalRequest),
		]);

		$signingKey = $this->getSigningKey($date, $region);
		$signature = hash_hmac('sha256', $stringToSign, $signingKey);

		return $algorithm . ' ' . implode(', ', [
			'Credential=' . $this->client->getAccessKey() . '/' . $scope,
			'SignedHeaders=' . $signedHeaders,
			'Signature=' . $signature,
		]);
	}

	private function getSigningKey($date, $region) {
		$keyDate = hash_hmac('sha256', $date, 'AWS4' . $this->client->getSecretKey(), true);
		$keyRegion = hash_hmac('sha256', $region, $keyDate, true);
		$keyService = hash_hmac('sha256', 'ses', $keyRegion, true);
		return hash_hmac('sha256', 'aws4_request', $keyService, true);
	}

	private function resetResponse() {
		$this->response = (object)[
			'body' => '',
			'code' => 0,
			'error' => false,
			'headers' => [],
			'request_id' => '',
		];
	}

	private function responseWriteCallback($curl, $data) {
		$this->response->body .= $data;
		return strlen($data);
	}

	private function responseHeaderCallback($curl, $header) {
		$length = strlen($header);
		$header = trim($header);

		if($header === '' || strpos($header, ':') === false) {
			return $length;
		}

		list($name, $value) = explode(':', $header, 2);
		$this->response->headers[strtolower(trim($name))] = trim($value);
		return $length;
	}

	public function __destruct() {
		if($this->curlHandler) {
			@curl_close($this->curlHandler);
		}
	}
}
