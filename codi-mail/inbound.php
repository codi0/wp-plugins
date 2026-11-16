<?php

defined('ABSPATH') || exit;

function codi_mail_inbound_account_id() {
	return str_replace('-', '', wp_generate_uuid4());
}

function codi_mail_inbound_providers() {
	return [
		'graph' => 'Microsoft 365 / Graph',
	];
}

function codi_mail_inbound_account_defaults($id = '') {
	return [
		'id' => $id !== '' ? sanitize_key((string)$id) : codi_mail_inbound_account_id(),
		'label' => '',
		'enabled' => false,
		'provider' => 'graph',
		'tenant_id' => '',
		'client_id' => '',
		'client_secret' => '',
		'mailbox' => '',
		'page_size' => 25,
	];
}

function codi_mail_inbound_sanitize_account(array $account, array $current = [], $id = '') {
	$id = sanitize_key((string)($id !== '' ? $id : ($account['id'] ?? '')));
	if($id === '') {
		$id = codi_mail_inbound_account_id();
	}

	$defaults = codi_mail_inbound_account_defaults($id);
	$current = array_merge($defaults, $current);
	$providers = codi_mail_inbound_providers();
	$provider = isset($account['provider']) ? sanitize_key((string)$account['provider']) : (string)$current['provider'];
	if(!isset($providers[$provider])) {
		$provider = 'graph';
	}

	$secret = isset($account['client_secret']) ? trim((string)$account['client_secret']) : '';
	if($secret === '') {
		$secret = (string)$current['client_secret'];
	}

	$pageSize = isset($account['page_size']) ? absint($account['page_size']) : (int)$current['page_size'];
	$pageSize = max(1, min(100, $pageSize));

	$mailbox = isset($account['mailbox'])
		? strtolower(trim(sanitize_email((string)$account['mailbox'])))
		: strtolower(trim((string)$current['mailbox']));

	return [
		'id' => $id,
		'label' => isset($account['label']) ? trim(sanitize_text_field((string)$account['label'])) : (string)$current['label'],
		'enabled' => !empty($account['enabled']),
		'provider' => $provider,
		'tenant_id' => isset($account['tenant_id']) ? trim(sanitize_text_field((string)$account['tenant_id'])) : (string)$current['tenant_id'],
		'client_id' => isset($account['client_id']) ? trim(sanitize_text_field((string)$account['client_id'])) : (string)$current['client_id'],
		'client_secret' => $secret,
		'mailbox' => $mailbox,
		'page_size' => $pageSize,
	];
}

function codi_mail_inbound_migrate_legacy() {
	$existing = get_option('codi_mail_inbound_accounts', null);
	if(is_array($existing)) {
		return;
	}

	$legacy = get_option('codi_mail_inbound_opts', []);
	if(!is_array($legacy) || (
		empty($legacy['enabled'])
		&& empty($legacy['mailbox'])
		&& empty($legacy['tenant_id'])
		&& empty($legacy['client_id'])
	)) {
		update_option('codi_mail_inbound_accounts', [], false);
		return;
	}

	$id = codi_mail_inbound_account_id();
	$account = codi_mail_inbound_sanitize_account(
		array_merge([ 'label' => !empty($legacy['mailbox']) ? (string)$legacy['mailbox'] : 'Microsoft 365 mailbox' ], $legacy),
		[],
		$id
	);
	update_option('codi_mail_inbound_accounts', [ $id => $account ], false);

	if(!empty($legacy['mailbox'])) {
		$oldCursor = 'codi_mail_graph_delta_' . md5((string)$legacy['mailbox']);
		$newCursor = 'codi_mail_graph_delta_' . $id;
		$cursor = get_option($oldCursor, '');
		if(is_string($cursor) && $cursor !== '') {
			update_option($newCursor, $cursor, false);
			delete_option($oldCursor);
		}
	}

	$health = get_option('codi_mail_inbound_health', []);
	if(is_array($health) && (
		isset($health['last_success_at'])
		|| isset($health['last_dispatched_at'])
		|| isset($health['last_error'])
	)) {
		update_option('codi_mail_inbound_health', [ $id => $health ], false);
	}

	delete_option('codi_mail_inbound_opts');
}

function codi_mail_inbound_reset_account_state($accountId) {
	$accountId = sanitize_key((string)$accountId);
	if($accountId === '') {
		return;
	}

	delete_option('codi_mail_graph_delta_' . $accountId);

	$health = codi_mail_inbound_health();
	if(isset($health[$accountId])) {
		unset($health[$accountId]);
		update_option('codi_mail_inbound_health', $health, false);
	}
}

function codi_mail_inbound_accounts(?array $accounts = null) {
	codi_mail_inbound_migrate_legacy();
	$current = get_option('codi_mail_inbound_accounts', []);
	if(!is_array($current)) {
		$current = [];
	}

	if(is_array($accounts)) {
		$clean = [];
		foreach($accounts as $id => $account) {
			if(!is_array($account)) {
				continue;
			}
			$id = sanitize_key((string)$id);
			if($id === '') {
				$id = codi_mail_inbound_account_id();
			}
			$existing = isset($current[$id]) && is_array($current[$id]) ? $current[$id] : [];
			$clean[$id] = codi_mail_inbound_sanitize_account($account, $existing, $id);
		}
		$clean = apply_filters('codi_mail_inbound_accounts', $clean);
		$resetState = [];
		foreach($clean as $id => $account) {
			$existing = isset($current[$id]) && is_array($current[$id]) ? $current[$id] : [];
			if(!$existing || !is_array($account)) {
				continue;
			}
			$oldProvider = sanitize_key((string)($existing['provider'] ?? ''));
			$oldMailbox = strtolower(trim((string)($existing['mailbox'] ?? '')));
			$newProvider = sanitize_key((string)($account['provider'] ?? ''));
			$newMailbox = strtolower(trim((string)($account['mailbox'] ?? '')));
			if($oldProvider !== $newProvider || $oldMailbox !== $newMailbox) {
				$resetState[] = $id;
			}
		}
		update_option('codi_mail_inbound_accounts', $clean, false);
		foreach(array_unique($resetState) as $id) {
			codi_mail_inbound_reset_account_state($id);
		}
		codi_mail_inbound_sync_schedule($clean);
		return $clean;
	}

	$clean = [];
	foreach($current as $id => $account) {
		if(is_array($account)) {
			$id = sanitize_key((string)$id);
			$clean[$id] = codi_mail_inbound_sanitize_account($account, $account, $id);
		}
	}
	return $clean;
}

function codi_mail_inbound_remove_account($accountId) {
	$accountId = sanitize_key((string)$accountId);
	$accounts = codi_mail_inbound_accounts();
	if(!isset($accounts[$accountId])) {
		return false;
	}

	unset($accounts[$accountId]);
	codi_mail_inbound_accounts($accounts);
	codi_mail_inbound_reset_account_state($accountId);

	return true;
}

function codi_mail_inbound_activate() {
	codi_mail_inbound_migrate_legacy();
	codi_mail_inbound_sync_schedule();
}

function codi_mail_inbound_deactivate() {
	$timestamp = wp_next_scheduled('codi_mail/inbound_poll');
	while($timestamp) {
		wp_unschedule_event($timestamp, 'codi_mail/inbound_poll');
		$timestamp = wp_next_scheduled('codi_mail/inbound_poll');
	}
}

function codi_mail_inbound_cron_schedules($schedules) {
	$schedules['codi_mail_five_minutes'] = [
		'interval' => 300,
		'display' => 'Every five minutes',
	];
	return $schedules;
}

function codi_mail_inbound_sync_schedule($accounts = null) {
	$accounts = is_array($accounts) ? $accounts : codi_mail_inbound_accounts();
	$scheduled = wp_next_scheduled('codi_mail/inbound_poll');
	$enabled = false;

	foreach($accounts as $account) {
		if(is_array($account) && !empty($account['enabled'])) {
			$enabled = true;
			break;
		}
	}

	if($enabled) {
		if(!$scheduled) {
			wp_schedule_event(time() + 60, 'codi_mail_five_minutes', 'codi_mail/inbound_poll');
		}
		return;
	}

	while($scheduled) {
		wp_unschedule_event($scheduled, 'codi_mail/inbound_poll');
		$scheduled = wp_next_scheduled('codi_mail/inbound_poll');
	}
}

function codi_mail_inbound_health($accountId = null, $update = null) {
	$health = get_option('codi_mail_inbound_health', []);
	if(!is_array($health)) {
		$health = [];
	}

	if($accountId === null) {
		return $health;
	}

	$accountId = sanitize_key((string)$accountId);
	$current = isset($health[$accountId]) && is_array($health[$accountId]) ? $health[$accountId] : [];
	if(is_array($update)) {
		$current = array_merge($current, $update);
		$health[$accountId] = $current;
		update_option('codi_mail_inbound_health', $health, false);
	}

	return $current;
}

function codi_mail_inbound_normalize_message(array $message) {
	$defaults = [
		'inbound_account_id' => '', 'inbound_account_label' => '', 'mailbox' => '',
		'provider' => '', 'provider_message_id' => '', 'provider_locator' => '',
		'message_id' => '', 'in_reply_to' => '', 'references' => [], 'envelope_to' => '',
		'to' => [], 'cc' => [], 'from_name' => '', 'from_email' => '', 'reply_to' => [],
		'subject' => '', 'text_body' => '', 'html_body' => '', 'attachments' => [],
		'received_at' => '', 'classification' => 'normal', 'headers' => [],
	];
	$message = array_merge($defaults, $message);

	$message['inbound_account_id'] = sanitize_key((string)$message['inbound_account_id']);
	$message['inbound_account_label'] = sanitize_text_field((string)$message['inbound_account_label']);
	$message['mailbox'] = strtolower(trim(sanitize_email((string)$message['mailbox'])));
	$message['provider'] = sanitize_key((string)$message['provider']);
	$message['provider_message_id'] = trim((string)$message['provider_message_id']);
	$message['provider_locator'] = trim((string)$message['provider_locator']);
	$message['message_id'] = trim((string)$message['message_id']);
	$message['in_reply_to'] = trim((string)$message['in_reply_to']);
	$message['references'] = array_values(array_filter(array_map('strval', (array)$message['references']), 'strlen'));
	$message['to'] = array_values(array_filter(array_map('strval', (array)$message['to']), 'strlen'));
	$message['cc'] = array_values(array_filter(array_map('strval', (array)$message['cc']), 'strlen'));
	$message['reply_to'] = array_values(array_filter(array_map('strval', (array)$message['reply_to']), 'strlen'));
	$message['attachments'] = array_values(array_filter((array)$message['attachments'], 'is_array'));
	$message['headers'] = is_array($message['headers']) ? $message['headers'] : [];

	if(!in_array($message['classification'], [ 'normal', 'auto_reply', 'automated', 'bounce' ], true)) {
		$message['classification'] = 'normal';
	}

	return $message;
}

function codi_mail_inbound_dispatch(array $message) {
	$message = codi_mail_inbound_normalize_message($message);
	if(
		$message['inbound_account_id'] === ''
		|| $message['mailbox'] === ''
		|| $message['provider'] === ''
		|| $message['provider_message_id'] === ''
	) {
		throw new InvalidArgumentException('Inbound mail requires account, mailbox, provider and provider_message_id.');
	}

	do_action('codi_mail_inbound_message', $message);
	codi_mail_inbound_health($message['inbound_account_id'], [ 'last_dispatched_at' => gmdate('c') ]);
	return true;
}

function codi_mail_inbound_poll_account(array $account) {
	$provider = sanitize_key((string)($account['provider'] ?? ''));
	try {
		if($provider !== 'graph') {
			throw new RuntimeException('Unsupported inbound mail provider.');
		}

		require_once __DIR__ . '/inbound/graph.php';
		$result = codi_mail_graph_poll($account);
		codi_mail_inbound_health($account['id'], [
			'last_success_at' => gmdate('c'),
			'last_error_at' => '',
			'last_error' => '',
			'consecutive_failures' => 0,
		]);
		return $result;
	} catch(Throwable $e) {
		$currentHealth = codi_mail_inbound_health($account['id']);
		codi_mail_inbound_health($account['id'], [
			'last_error_at' => gmdate('c'),
			'last_error' => $e->getMessage(),
			'consecutive_failures' => (int)($currentHealth['consecutive_failures'] ?? 0) + 1,
		]);
		throw $e;
	}
}

function codi_mail_inbound_poll($accountId = '') {
	$accounts = codi_mail_inbound_accounts();
	$accountId = sanitize_key((string)$accountId);

	if($accountId !== '') {
		if(empty($accounts[$accountId]) || !is_array($accounts[$accountId])) {
			return new WP_Error('codi_mail_inbound_account_missing', 'Inbound mail account not found.');
		}
		try {
			return codi_mail_inbound_poll_account($accounts[$accountId]);
		} catch(Throwable $e) {
			return new WP_Error('codi_mail_inbound_failed', $e->getMessage());
		}
	}

	$result = [
		'accounts' => 0,
		'failed' => 0,
		'dispatched' => 0,
	];

	foreach($accounts as $account) {
		if(!is_array($account) || empty($account['enabled'])) {
			continue;
		}

		$result['accounts']++;
		try {
			$accountResult = codi_mail_inbound_poll_account($account);
			$result['dispatched'] += (int)($accountResult['dispatched'] ?? 0);
		} catch(Throwable $e) {
			$result['failed']++;
		}
	}

	return $result;
}

add_filter('cron_schedules', 'codi_mail_inbound_cron_schedules');
add_action('init', 'codi_mail_inbound_sync_schedule', 20);
add_action('codi_mail/inbound_poll', 'codi_mail_inbound_poll');
