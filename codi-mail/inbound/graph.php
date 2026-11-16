<?php

defined('ABSPATH') || exit;

function codi_mail_graph_token(array $opts) {
	$key = 'codi_mail_graph_' . md5($opts['tenant_id'] . "\0" . $opts['client_id']);
	$cached = get_transient($key);
	if(is_string($cached) && $cached !== '') {
		return $cached;
	}

	if($opts['tenant_id'] === '' || $opts['client_id'] === '' || $opts['client_secret'] === '') {
		throw new RuntimeException('Microsoft Graph inbound credentials are incomplete.');
	}

	$url = 'https://login.microsoftonline.com/' . rawurlencode($opts['tenant_id']) . '/oauth2/v2.0/token';
	$response = wp_remote_post($url, [
		'timeout' => 20,
		'body' => [
			'client_id' => $opts['client_id'],
			'client_secret' => $opts['client_secret'],
			'scope' => 'https://graph.microsoft.com/.default',
			'grant_type' => 'client_credentials',
		],
	]);

	if(is_wp_error($response)) {
		throw new RuntimeException($response->get_error_message());
	}

	$code = (int)wp_remote_retrieve_response_code($response);
	$data = json_decode(wp_remote_retrieve_body($response), true);
	if($code < 200 || $code >= 300 || !is_array($data) || empty($data['access_token'])) {
		$message = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : 'Microsoft Graph authentication failed.';
		throw new RuntimeException($message, $code);
	}

	$ttl = isset($data['expires_in']) ? max(60, (int)$data['expires_in'] - 120) : 3000;
	set_transient($key, (string)$data['access_token'], $ttl);
	return (string)$data['access_token'];
}

function codi_mail_graph_request($url, $token) {
	$response = wp_remote_get($url, [
		'timeout' => 30,
		'headers' => [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/json',
			'Prefer' => 'IdType="ImmutableId", outlook.body-content-type="html"',
		],
	]);

	if(is_wp_error($response)) {
		throw new RuntimeException($response->get_error_message());
	}

	$code = (int)wp_remote_retrieve_response_code($response);
	$data = json_decode(wp_remote_retrieve_body($response), true);
	if($code < 200 || $code >= 300 || !is_array($data)) {
		$message = is_array($data) && isset($data['error']['message']) ? (string)$data['error']['message'] : 'Microsoft Graph request failed.';
		throw new RuntimeException($message, $code);
	}

	return $data;
}

function codi_mail_graph_headers(array $headers) {
	$result = [];
	foreach($headers as $header) {
		if(!is_array($header) || empty($header['name'])) {
			continue;
		}
		$name = strtolower(trim((string)$header['name']));
		$value = isset($header['value']) ? trim((string)$header['value']) : '';
		if($name !== '') {
			$result[$name][] = $value;
		}
	}
	return $result;
}

function codi_mail_graph_header_first(array $headers, $name) {
	$key = strtolower((string)$name);
	return !empty($headers[$key][0]) ? (string)$headers[$key][0] : '';
}

function codi_mail_graph_message_ids($value) {
	preg_match_all('/<[^>]+>/', (string)$value, $matches);
	if(!empty($matches[0])) {
		return array_values(array_unique(array_map('trim', $matches[0])));
	}
	$value = trim((string)$value);
	return $value === '' ? [] : [ $value ];
}

function codi_mail_graph_addresses($recipients) {
	$result = [];
	foreach((array)$recipients as $recipient) {
		$email = is_array($recipient) ? ($recipient['emailAddress'] ?? []) : [];
		if(is_array($email) && !empty($email['address'])) {
			$result[] = (string)$email['address'];
		}
	}
	return array_values(array_unique($result));
}

function codi_mail_graph_original_recipient(array $headers) {
	$candidates = [
		'x-original-to',
		'delivered-to',
		'x-envelope-to',
		'x-ms-exchange-organization-originalrecipient',
	];

	foreach($candidates as $name) {
		$value = codi_mail_graph_header_first($headers, $name);
		if($value !== '') {
			if(preg_match('/<([^>]+)>/', $value, $match)) {
				return trim($match[1]);
			}
			return trim($value);
		}
	}

	return '';
}

function codi_mail_graph_classification(array $headers, $fromEmail, $subject) {
	$autoSubmitted = strtolower(codi_mail_graph_header_first($headers, 'auto-submitted'));
	$precedence = strtolower(codi_mail_graph_header_first($headers, 'precedence'));
	$contentType = strtolower(codi_mail_graph_header_first($headers, 'content-type'));
	$returnPath = trim(codi_mail_graph_header_first($headers, 'return-path'));
	$from = strtolower((string)$fromEmail);
	$subject = strtolower((string)$subject);

	if($returnPath === '<>' || strpos($from, 'mailer-daemon@') !== false || strpos($from, 'postmaster@') !== false || strpos($contentType, 'report-type=delivery-status') !== false) {
		return 'bounce';
	}

	if($autoSubmitted !== '' && $autoSubmitted !== 'no') {
		return strpos($autoSubmitted, 'auto-replied') !== false ? 'auto_reply' : 'automated';
	}

	if(in_array($precedence, [ 'bulk', 'junk', 'list' ], true)) {
		return 'automated';
	}

	if(strpos($subject, 'automatic reply') === 0 || strpos($subject, 'out of office') !== false) {
		return 'auto_reply';
	}

	return 'normal';
}

function codi_mail_graph_attachment_metadata(array $attachment, $mailbox, $messageId) {
	return [
		'provider_locator' => 'graph:' . rawurlencode((string)$mailbox) . ':' . rawurlencode((string)$messageId) . ':' . rawurlencode((string)($attachment['id'] ?? '')),
		'id' => isset($attachment['id']) ? (string)$attachment['id'] : '',
		'name' => isset($attachment['name']) ? (string)$attachment['name'] : '',
		'content_type' => isset($attachment['contentType']) ? (string)$attachment['contentType'] : '',
		'size' => isset($attachment['size']) ? (int)$attachment['size'] : 0,
		'is_inline' => !empty($attachment['isInline']),
	];
}

function codi_mail_graph_fetch_message(array $opts, $token, $messageId) {
	$mailbox = rawurlencode($opts['mailbox']);
	$id = rawurlencode((string)$messageId);
	$select = rawurlencode('id,internetMessageId,internetMessageHeaders,receivedDateTime,subject,body,from,toRecipients,ccRecipients,replyTo,hasAttachments');
	$url = "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$id}?\$select={$select}";
	$message = codi_mail_graph_request($url, $token);
	$attachments = [];

	if(!empty($message['hasAttachments'])) {
		$attachmentData = codi_mail_graph_request("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$id}/attachments?\$select=id,name,contentType,size,isInline", $token);
		foreach((array)($attachmentData['value'] ?? []) as $attachment) {
			if(is_array($attachment)) {
				$attachments[] = codi_mail_graph_attachment_metadata($attachment, $opts['mailbox'], $messageId);
			}
		}
	}

	$headers = codi_mail_graph_headers((array)($message['internetMessageHeaders'] ?? []));
	$to = codi_mail_graph_addresses($message['toRecipients'] ?? []);
	$cc = codi_mail_graph_addresses($message['ccRecipients'] ?? []);
	$replyTo = codi_mail_graph_addresses($message['replyTo'] ?? []);
	$fromAddress = is_array($message['from']['emailAddress'] ?? null) ? $message['from']['emailAddress'] : [];
	$body = is_array($message['body'] ?? null) ? $message['body'] : [];
	$html = strtolower((string)($body['contentType'] ?? '')) === 'html' ? (string)($body['content'] ?? '') : '';
	$text = $html !== '' ? trim(wp_strip_all_tags(str_replace([ '<br>', '<br/>', '<br />', '</p>' ], "\n", $html))) : (string)($body['content'] ?? '');
	$inReplyTo = codi_mail_graph_header_first($headers, 'in-reply-to');
	$references = codi_mail_graph_message_ids(codi_mail_graph_header_first($headers, 'references'));

	return codi_mail_inbound_normalize_message([
		'inbound_account_id' => (string)($opts['id'] ?? ''),
		'inbound_account_label' => (string)($opts['label'] ?? ''),
		'mailbox' => strtolower((string)$opts['mailbox']),
		'provider' => 'graph',
		'provider_message_id' => (string)($message['id'] ?? $messageId),
		'provider_locator' => 'graph:' . $opts['mailbox'] . ':' . (string)($message['id'] ?? $messageId),
		'message_id' => (string)($message['internetMessageId'] ?? ''),
		'in_reply_to' => $inReplyTo,
		'references' => $references,
		'envelope_to' => codi_mail_graph_original_recipient($headers),
		'to' => $to,
		'cc' => $cc,
		'from_name' => isset($fromAddress['name']) ? (string)$fromAddress['name'] : '',
		'from_email' => isset($fromAddress['address']) ? (string)$fromAddress['address'] : '',
		'reply_to' => $replyTo,
		'subject' => (string)($message['subject'] ?? ''),
		'text_body' => $text,
		'html_body' => $html,
		'attachments' => $attachments,
		'received_at' => (string)($message['receivedDateTime'] ?? ''),
		'classification' => codi_mail_graph_classification($headers, (string)($fromAddress['address'] ?? ''), (string)($message['subject'] ?? '')),
		'headers' => $headers,
	]);
}

function codi_mail_graph_initial_delta_url(array $opts) {
	$mailbox = rawurlencode($opts['mailbox']);
	$top = max(1, min(100, (int)$opts['page_size']));
	$select = rawurlencode('id,receivedDateTime');
	return "https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders/inbox/messages/delta?changeType=created&\$select={$select}&\$top={$top}";
}

function codi_mail_graph_poll(array $opts) {
	if(empty($opts['id'])) {
		throw new RuntimeException('Microsoft Graph inbound account id is missing.');
	}
	if($opts['mailbox'] === '') {
		throw new RuntimeException('Microsoft Graph inbound mailbox is not configured.');
	}

	$token = codi_mail_graph_token($opts);
	$cursorOption = 'codi_mail_graph_delta_' . sanitize_key((string)$opts['id']);
	$url = get_option($cursorOption, '');
	if(!is_string($url) || $url === '') {
		$url = codi_mail_graph_initial_delta_url($opts);
	}

	$dispatched = 0;
	$finalDelta = '';
	$seenPages = 0;

	while($url !== '') {
		$seenPages++;
		if($seenPages > 1000) {
			throw new RuntimeException('Microsoft Graph delta pagination exceeded the safety limit.');
		}

		try {
			$page = codi_mail_graph_request($url, $token);
		} catch(RuntimeException $e) {
			if((int)$e->getCode() === 410) {
				delete_option($cursorOption);
			}
			throw $e;
		}

		foreach((array)($page['value'] ?? []) as $entry) {
			if(!is_array($entry) || empty($entry['id']) || !empty($entry['@removed'])) {
				continue;
			}

			try {
				$message = codi_mail_graph_fetch_message($opts, $token, (string)$entry['id']);
			} catch(RuntimeException $e) {
				if((int)$e->getCode() === 404) {
					continue;
				}
				throw $e;
			}
			if(codi_mail_inbound_dispatch($message)) {
				$dispatched++;
			}
		}

		if(!empty($page['@odata.nextLink'])) {
			$url = (string)$page['@odata.nextLink'];
			continue;
		}

		$finalDelta = !empty($page['@odata.deltaLink']) ? (string)$page['@odata.deltaLink'] : '';
		$url = '';
	}

	if($finalDelta === '') {
		throw new RuntimeException('Microsoft Graph delta response did not provide a durable cursor.');
	}

	update_option($cursorOption, $finalDelta, false);
	return [ 'dispatched' => $dispatched ];
}