<?php

defined('ABSPATH') or die;

function codi_debug_default_settings() {
	return [
		'notifications_enabled' => 0,
		'recipient' => codi_debug_default_recipient(),
		'alert_enabled' => 1,
		'alert_interval' => 'codi_debug_5_minutes',
		'alert_min_severity' => 'critical',
		'summary_enabled' => 1,
		'summary_interval' => 'codi_debug_daily',
	];
}

function codi_debug_default_recipient() {
	if(is_multisite()) {
		$email = (string) get_site_option('admin_email', '');
		if($email !== '') {
			return $email;
		}
	}
	return (string) get_option('admin_email', '');
}

function codi_debug_storage_get($key, $default = null) {
	return is_multisite()
		? get_site_option($key, $default)
		: get_option($key, $default);
}

function codi_debug_storage_update($key, $value) {
	return is_multisite()
		? update_site_option($key, $value)
		: update_option($key, $value);
}

function codi_debug_get_settings() {
	$stored = codi_debug_storage_get('codi_debug_settings', []);
	$settings = array_merge(
		codi_debug_default_settings(),
		is_array($stored) ? $stored : []
	);
	//1.1.1 used "other" as the sentinel for all severities. Preserve the
	//saved intent while keeping "other" out of the actual severity model.
	if(($settings['alert_min_severity'] ?? '') === 'other') {
		$settings['alert_min_severity'] = 'all';
	}
	return $settings;
}

function codi_debug_update_settings(array $settings) {
	return codi_debug_storage_update('codi_debug_settings', $settings);
}

function codi_debug_interval_choices() {
	return [
		'codi_debug_5_minutes' => [ 'seconds' => 300, 'label' => 'Every 5 minutes' ],
		'codi_debug_15_minutes' => [ 'seconds' => 900, 'label' => 'Every 15 minutes' ],
		'codi_debug_hourly' => [ 'seconds' => 3600, 'label' => 'Hourly' ],
		'codi_debug_6_hours' => [ 'seconds' => 21600, 'label' => 'Every 6 hours' ],
		'codi_debug_12_hours' => [ 'seconds' => 43200, 'label' => 'Every 12 hours' ],
		'codi_debug_daily' => [ 'seconds' => 86400, 'label' => 'Daily' ],
	];
}

function codi_debug_severity_choices() {
	return [
		'critical' => 'Critical',
		'warning' => 'Warning',
		'notice' => 'Notice',
		'deprecated' => 'Deprecated',
	];
}

function codi_debug_min_severity_choices() {
	$choices = codi_debug_severity_choices();
	$choices['all'] = 'All severities';
	return $choices;
}

function codi_debug_sanitize_settings($input) {
	$defaults = codi_debug_default_settings();
	$input = is_array($input) ? $input : [];
	$intervals = codi_debug_interval_choices();
	$severities = codi_debug_min_severity_choices();
	$recipient = isset($input['recipient']) ? sanitize_email($input['recipient']) : '';

	return [
		'notifications_enabled' => empty($input['notifications_enabled']) ? 0 : 1,
		'recipient' => $recipient ?: $defaults['recipient'],
		'alert_enabled' => empty($input['alert_enabled']) ? 0 : 1,
		'alert_interval' => isset($intervals[$input['alert_interval'] ?? ''])
			? $input['alert_interval']
			: $defaults['alert_interval'],
		'alert_min_severity' => isset($severities[$input['alert_min_severity'] ?? ''])
			? $input['alert_min_severity']
			: $defaults['alert_min_severity'],
		'summary_enabled' => empty($input['summary_enabled']) ? 0 : 1,
		'summary_interval' => isset($intervals[$input['summary_interval'] ?? ''])
			? $input['summary_interval']
			: $defaults['summary_interval'],
	];
}

function codi_debug_cron_schedules($schedules) {
	foreach(codi_debug_interval_choices() as $key => $meta) {
		$schedules[$key] = [
			'interval' => $meta['seconds'],
			'display' => $meta['label'],
		];
	}
	return $schedules;
}

function codi_debug_is_network_active() {
	if(!is_multisite()) {
		return false;
	}
	if(!function_exists('is_plugin_active_for_network')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return function_exists('is_plugin_active_for_network')
		&& is_plugin_active_for_network(plugin_basename(CODI_DEBUG_PLUGIN_FILE));
}

function codi_debug_network_mode() {
	return is_multisite() && codi_debug_is_network_active();
}

function codi_debug_admin_capability() {
	return codi_debug_network_mode() ? 'manage_network_options' : 'manage_options';
}

function codi_debug_settings_url() {
	return codi_debug_network_mode()
		? network_admin_url('admin.php?page=codi-debug')
		: admin_url('options-general.php?page=codi-debug');
}

function codi_debug_errors_url() {
	return codi_debug_network_mode()
		? network_admin_url('admin.php?page=codi-debug-errors')
		: admin_url('tools.php?page=codi-debug-errors');
}

function codi_debug_should_schedule_here() {
	return !codi_debug_is_network_active() || is_main_site();
}

function codi_debug_sync_schedules() {
	if(!codi_debug_should_schedule_here()) {
		wp_clear_scheduled_hook('codi_debug/error_alerts');
		wp_clear_scheduled_hook('codi_debug/error_summary');
		return;
	}

	$settings = codi_debug_get_settings();
	$master = !empty($settings['notifications_enabled']);
	codi_debug_sync_schedule(
		'codi_debug/error_alerts',
		$master && !empty($settings['alert_enabled']),
		$settings['alert_interval']
	);
	codi_debug_sync_schedule(
		'codi_debug/error_summary',
		$master && !empty($settings['summary_enabled']),
		$settings['summary_interval']
	);
}

function codi_debug_sync_schedule($hook, $enabled, $schedule) {
	if(!$enabled) {
		wp_clear_scheduled_hook($hook);
		return;
	}

	$event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event($hook) : null;
	if($event && $event->schedule === $schedule) {
		return;
	}
	if($event || wp_next_scheduled($hook)) {
		wp_clear_scheduled_hook($hook);
	}
	wp_schedule_event(time() + 60, $schedule, $hook);
}

function codi_debug_resync_schedules() {
	if(is_multisite() && codi_debug_is_network_active() && !is_main_site()) {
		$current = get_current_blog_id();
		switch_to_blog(get_main_site_id());
		codi_debug_sync_schedules();
		restore_current_blog();
		return;
	}
	codi_debug_sync_schedules();
}

function codi_debug_activate($networkWide = false) {
	codi_debug_prime_monitor_state();
	codi_debug_resync_schedules();
}

function codi_debug_deactivate($networkWide = false) {
	if($networkWide && is_multisite()) {
		foreach(get_sites([ 'fields' => 'ids', 'number' => 0 ]) as $siteId) {
			switch_to_blog($siteId);
			wp_clear_scheduled_hook('codi_debug/error_alerts');
			wp_clear_scheduled_hook('codi_debug/error_summary');
			restore_current_blog();
		}
		return;
	}
	wp_clear_scheduled_hook('codi_debug/error_alerts');
	wp_clear_scheduled_hook('codi_debug/error_summary');
}

function codi_debug_error_log_path() {
	$path = defined('CODI_DEBUG_ERROR_LOG') ? trim((string) CODI_DEBUG_ERROR_LOG) : '';
	if($path === '') {
		$path = trim((string) ini_get('error_log'));
	}
	if($path === '' || strtolower($path) === 'syslog' || preg_match('/^[a-z][a-z0-9+.\-]*:\/\//i', $path)) {
		$path = '';
	}
	if($path === '' && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
		$path = is_string(WP_DEBUG_LOG)
			? WP_DEBUG_LOG
			: (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : '');
	}
	if($path !== '' && !preg_match('/^(?:[a-zA-Z]:[\\\\\/]|\/|\\\\\\\\)/', $path) && defined('ABSPATH')) {
		$path = rtrim(ABSPATH, '/\\') . '/' . ltrim($path, '/\\');
	}
	if(function_exists('wp_normalize_path')) {
		$path = wp_normalize_path($path);
	}
	return (string) apply_filters('codi_debug_error_log_path', $path);
}

function codi_debug_error_log_info() {
	$path = codi_debug_error_log_path();
	$readable = $path !== '' && is_file($path) && is_readable($path);
	return [
		'path' => $path,
		'readable' => $readable,
		'size' => $readable ? (int) filesize($path) : 0,
		'modified' => $readable ? (int) filemtime($path) : 0,
	];
}

function codi_debug_classify_severity($message) {
	$message = strtolower((string) $message);
	if(preg_match('/(?:\be[_\s-]?user[_\s-]?warning\b|\be[_\s-]?warning\b|\buser[_\s-]?warning\b|\bwarning\b)/i', $message)) {
		return 'warning';
	}
	if(preg_match('/(?:\be[_\s-]?user[_\s-]?notice\b|\be[_\s-]?notice\b|\buser[_\s-]?notice\b|\bnotice\b|\be[_\s-]?strict\b|\bphp\s+strict\s*:)/i', $message)) {
		return 'notice';
	}
	if(preg_match('/(?:\be[_\s-]?user[_\s-]?deprecated\b|\be[_\s-]?deprecated\b|\buser[_\s-]?deprecated\b|\bdeprecated\b)/i', $message)) {
		return 'deprecated';
	}
	return 'critical';
}

function codi_debug_ignore_log_message($message) {
	$message = trim((string) $message);
	return (bool) preg_match('/^(?:[\'\"]?#{6}\s*wp_scraping_result_(?:start|end):|Scraping home page\.\.\.)/i', $message);
}

function codi_debug_site_map() {
	static $sites = null;
	if($sites !== null) {
		return $sites;
	}
	$sites = [];
	if(!function_exists('home_url')) {
		return $sites;
	}

	$siteIds = function_exists('is_multisite') && is_multisite() && function_exists('get_sites')
		? get_sites([ 'fields' => 'ids', 'number' => 0 ])
		: [ function_exists('get_current_blog_id') ? get_current_blog_id() : 0 ];

	foreach($siteIds as $siteId) {
		$url = $siteId && function_exists('get_site_url') ? get_site_url($siteId, '/') : home_url('/');
		$parts = wp_parse_url($url);
		if(empty($parts['host'])) {
			continue;
		}
		$path = isset($parts['path']) ? '/' . trim($parts['path'], '/') : '/';
		if($path !== '/') $path .= '/';
		$name = $parts['host'] . ($path === '/' ? '' : rtrim($path, '/'));
		if($siteId && function_exists('get_blog_details')) {
			$details = get_blog_details($siteId, false);
			if($details && !empty($details->blogname)) {
				$name = $details->blogname;
			}
		}
		$sites[] = [
			'id' => (int) $siteId,
			'name' => $name,
			'host' => strtolower($parts['host']),
			'path' => $path,
		];
	}
	usort($sites, function($a, $b) {
		return strlen($b['path']) <=> strlen($a['path']);
	});
	return $sites;
}

function codi_debug_error_site($message) {
	$message = (string) $message;
	if(!preg_match_all('/https?:\/\/[^\s\)\]\>\"\']+/i', $message, $matches)) {
		return [ 'id' => 0, 'name' => 'Unknown' ];
	}
	foreach($matches[0] as $url) {
		$parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
		if(empty($parts['host'])) {
			continue;
		}
		$host = strtolower($parts['host']);
		$path = isset($parts['path']) ? '/' . ltrim($parts['path'], '/') : '/';
		foreach(codi_debug_site_map() as $site) {
			if($site['host'] !== $host) {
				continue;
			}
			if($site['path'] !== '/' && strpos($path . '/', $site['path']) !== 0) {
				continue;
			}
			return [ 'id' => $site['id'], 'name' => $site['name'] ];
		}
	}
	return [ 'id' => 0, 'name' => 'Unknown' ];
}

function codi_debug_severity_rank($severity) {
	$ranks = [
		'critical' => 50,
		'warning' => 40,
		'notice' => 30,
		'deprecated' => 20,
	];
	return $ranks[$severity] ?? 0;
}

function codi_debug_make_error_entry($timestamp, $message) {
	$message = trim((string) $message);
	$firstLine = trim(strtok($message, "\r\n") ?: $message);
	$site = codi_debug_error_site($message);
	return [
		'timestamp' => trim((string) $timestamp),
		'severity' => codi_debug_classify_severity($message),
		'message' => $message,
		'fingerprint' => sha1(strtolower(preg_replace('/\s+/', ' ', $firstLine))),
		'site_id' => $site['id'],
		'site' => $site['name'],
	];
}

function codi_debug_parse_log_text($text) {
	$entries = [];
	$current = null;
	$lines = preg_split('/\r\n|\n|\r/', (string) $text);

	$flush = function() use (&$entries, &$current) {
		if($current && trim($current['message']) !== '' && !codi_debug_ignore_log_message($current['message'])) {
			$entries[] = codi_debug_make_error_entry($current['timestamp'], $current['message']);
		}
		$current = null;
	};

	foreach($lines as $line) {
		if($line === '') {
			continue;
		}
		if(preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $match)) {
			$flush();
			$current = [ 'timestamp' => $match[1], 'message' => $match[2] ];
			continue;
		}
		if(preg_match('/^(?:PHP\s+)?(?:Fatal error|Recoverable fatal|Parse error|Core error|Compile error|Error|Warning|Notice|Deprecated|Uncaught)\b/i', $line)) {
			$flush();
			$current = [ 'timestamp' => '', 'message' => $line ];
			continue;
		}
		if($current) {
			$current['message'] .= "\n" . $line;
		}
	}
	$flush();

	return $entries;
}

function codi_debug_read_recent_entries($maxEntries = 1000, $maxBytes = 2097152) {
	$info = codi_debug_error_log_info();
	if(!$info['readable']) {
		return [];
	}

	$handle = @fopen($info['path'], 'rb');
	if(!$handle) {
		return [];
	}
	$start = max(0, $info['size'] - (int) $maxBytes);
	if($start > 0) {
		fseek($handle, $start);
		fgets($handle);
	} else {
		fseek($handle, 0);
	}
	$text = stream_get_contents($handle);
	fclose($handle);

	$entries = codi_debug_parse_log_text($text ?: '');
	return array_slice($entries, -1 * (int) $maxEntries);
}

function codi_debug_monitor_state() {
	$state = codi_debug_storage_get('codi_debug_error_monitor_state', []);
	return is_array($state) ? $state : [];
}

function codi_debug_update_monitor_state(array $state) {
	return codi_debug_storage_update('codi_debug_error_monitor_state', $state);
}

function codi_debug_prime_channel($channel) {
	$info = codi_debug_error_log_info();
	if(!$info['readable']) {
		return false;
	}
	$state = codi_debug_monitor_state();
	$inode = @fileinode($info['path']);
	$state[$channel] = [
		'path' => $info['path'],
		'inode' => $inode === false ? '' : (string) $inode,
		'offset' => $info['size'],
		'buffer' => '',
	];
	return codi_debug_update_monitor_state($state);
}

function codi_debug_prime_monitor_state() {
	codi_debug_prime_channel('alerts');
	codi_debug_prime_channel('summary');
}

function codi_debug_read_new_entries($channel, $maxBytes = 2097152) {
	$info = codi_debug_error_log_info();
	if(!$info['readable']) {
		return [];
	}

	$state = codi_debug_monitor_state();
	$currentInode = @fileinode($info['path']);
	$currentInode = $currentInode === false ? '' : (string) $currentInode;
	$cursor = isset($state[$channel]) && is_array($state[$channel]) ? $state[$channel] : null;

	if(!$cursor || ($cursor['path'] ?? '') !== $info['path']) {
		codi_debug_prime_channel($channel);
		return [];
	}

	$offset = max(0, (int) ($cursor['offset'] ?? 0));
	$buffer = (string) ($cursor['buffer'] ?? '');
	if(($cursor['inode'] ?? '') !== '' && $currentInode !== '' && $cursor['inode'] !== $currentInode) {
		$offset = 0;
		$buffer = '';
	} elseif($offset > $info['size']) {
		$offset = 0;
		$buffer = '';
	}

	$handle = @fopen($info['path'], 'rb');
	if(!$handle || fseek($handle, $offset) !== 0) {
		if($handle) fclose($handle);
		return [];
	}
	$chunk = fread($handle, max(1, (int) $maxBytes));
	$newOffset = ftell($handle);
	fclose($handle);
	if($chunk === false || $chunk === '') {
		return [];
	}

	$data = $buffer . $chunk;
	$lastLf = strrpos($data, "\n");
	$lastCr = strrpos($data, "\r");
	$lastBreak = max($lastLf === false ? -1 : $lastLf, $lastCr === false ? -1 : $lastCr);
	if($lastBreak < 0) {
		$complete = '';
		$buffer = substr($data, -262144);
	} else {
		$complete = substr($data, 0, $lastBreak + 1);
		$buffer = substr($data, $lastBreak + 1);
	}

	$state[$channel] = [
		'path' => $info['path'],
		'inode' => $currentInode,
		'offset' => $newOffset,
		'buffer' => $buffer,
	];
	codi_debug_update_monitor_state($state);

	return $complete === '' ? [] : codi_debug_parse_log_text($complete);
}

function codi_debug_filter_min_severity(array $entries, $minimum) {
	if($minimum === 'all') {
		return array_values($entries);
	}
	$minimumRank = codi_debug_severity_rank($minimum);
	return array_values(array_filter($entries, function($entry) use($minimumRank) {
		return codi_debug_severity_rank($entry['severity'] ?? 'critical') >= $minimumRank;
	}));
}

function codi_debug_error_message_parts($message) {
	$lines = preg_split('/\r\n|\n|\r/', trim((string) $message));
	$first = trim((string) array_shift($lines));
	if(strlen($first) > 800) {
		$first = substr($first, 0, 797) . '...';
	}
	$details = trim(implode("\n", array_slice($lines, 0, 12)));
	if(strlen($details) > 1200) {
		$details = substr($details, 0, 1197) . '...';
	}
	return [ $first, $details ];
}

function codi_debug_group_entries(array $entries) {
	$groups = [];
	foreach($entries as $entry) {
		$key = $entry['fingerprint'];
		if(!isset($groups[$key])) {
			$groups[$key] = [
				'count' => 0,
				'severity' => $entry['severity'],
				'message' => $entry['message'],
				'first' => $entry['timestamp'],
				'last' => $entry['timestamp'],
				'sites' => [],
			];
		}
		$groups[$key]['count']++;
		$groups[$key]['last'] = $entry['timestamp'];
		$site = $entry['site'] ?? 'Unknown';
		$groups[$key]['sites'][$site] = true;
	}
	usort($groups, function($a, $b) {
		if($a['count'] === $b['count']) {
			return codi_debug_severity_rank($b['severity']) <=> codi_debug_severity_rank($a['severity']);
		}
		return $b['count'] <=> $a['count'];
	});
	return $groups;
}

function codi_debug_notification_body($title, array $entries) {
	$groups = codi_debug_group_entries($entries);
	$lines = [
		$title,
		'',
		(codi_debug_network_mode() ? 'Scope: WordPress network' : 'Site: ' . (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: home_url('/'))),
		'Entries: ' . count($entries) . ' (' . count($groups) . ' unique)',
		'Error log: ' . codi_debug_error_log_path(),
		'',
	];

	foreach(array_slice($groups, 0, 25) as $group) {
		list($firstLine, $details) = codi_debug_error_message_parts($group['message']);
		$lines[] = sprintf('[%s] %dx', strtoupper($group['severity']), $group['count']);
		if($group['first']) $lines[] = 'First: ' . $group['first'];
		if($group['last']) $lines[] = 'Last: ' . $group['last'];
		$lines[] = 'Site: ' . implode(', ', array_keys($group['sites']));
		$lines[] = 'Message: ' . $firstLine;
		if($details !== '') {
			$lines[] = 'Details:';
			$lines[] = $details;
		}
		$lines[] = '';
	}

	if(count($groups) > 25) {
		$lines[] = sprintf('%d additional unique errors omitted from this email.', count($groups) - 25);
		$lines[] = '';
	}
	$lines[] = 'View recent errors: ' . codi_debug_errors_url();
	return implode("\n", $lines);
}

function codi_debug_send_notification($subject, $title, array $entries) {
	if(!$entries) {
		return false;
	}
	$settings = codi_debug_get_settings();
	$recipient = sanitize_email($settings['recipient'] ?? '');
	if(!$recipient) {
		return false;
	}
	$sent = wp_mail($recipient, $subject, codi_debug_notification_body($title, $entries));
	if(!$sent) {
		error_log('[Codi Debug] Unable to send PHP error notification email');
	}
	return (bool) $sent;
}

function codi_debug_run_alerts() {
	$settings = codi_debug_get_settings();
	if(empty($settings['notifications_enabled']) || empty($settings['alert_enabled'])) {
		return;
	}
	$entries = codi_debug_filter_min_severity(
		codi_debug_read_new_entries('alerts'),
		$settings['alert_min_severity']
	);
	if(!$entries) {
		return;
	}
	$host = codi_debug_network_mode() ? 'WordPress network' : (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'WordPress');
	codi_debug_send_notification(
		'[Codi Debug] Urgent PHP errors on ' . $host,
		'Urgent PHP errors detected',
		$entries
	);
}

function codi_debug_run_summary() {
	$settings = codi_debug_get_settings();
	if(empty($settings['notifications_enabled']) || empty($settings['summary_enabled'])) {
		return;
	}
	$entries = codi_debug_read_new_entries('summary');
	if(!$entries) {
		return;
	}
	$host = codi_debug_network_mode() ? 'WordPress network' : (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'WordPress');
	codi_debug_send_notification(
		'[Codi Debug] PHP error summary for ' . $host,
		'PHP error summary',
		$entries
	);
}