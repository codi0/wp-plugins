<?php

defined('ABSPATH') or die;

add_action('admin_menu', 'codi_debug_admin_menu');
add_action('network_admin_menu', 'codi_debug_network_admin_menu');
add_action('admin_post_codi_debug_save_settings', 'codi_debug_save_settings');

function codi_debug_admin_menu() {
	if(codi_debug_network_mode()) {
		return;
	}
	add_options_page(
		'Codi Debug',
		'Codi Debug',
		'manage_options',
		'codi-debug',
		'codi_debug_settings_page'
	);
	add_management_page(
		'PHP Error Log',
		'PHP Error Log',
		'manage_options',
		'codi-debug-errors',
		'codi_debug_errors_page'
	);
}

function codi_debug_network_admin_menu() {
	if(!codi_debug_network_mode()) {
		return;
	}
	$capability = codi_debug_admin_capability();
	add_menu_page(
		'PHP Error Log',
		'Codi Debug',
		$capability,
		'codi-debug-errors',
		'codi_debug_errors_page',
		'dashicons-warning',
		80
	);
	add_submenu_page(
		'codi-debug-errors',
		'Codi Debug Settings',
		'Settings',
		$capability,
		'codi-debug',
		'codi_debug_settings_page'
	);
}

function codi_debug_save_settings() {
	if(!current_user_can(codi_debug_admin_capability())) {
		wp_die('You are not allowed to change Codi Debug settings.', 'Codi Debug', [ 'response' => 403 ]);
	}
	check_admin_referer('codi_debug_save_settings');
	$old = codi_debug_get_settings();
	$new = codi_debug_sanitize_settings($_POST['codi_debug'] ?? []);
	codi_debug_update_settings($new);

	$masterEnabled = empty($old['notifications_enabled']) && !empty($new['notifications_enabled']);
	if($masterEnabled || (empty($old['alert_enabled']) && !empty($new['alert_enabled']))) {
		codi_debug_prime_channel('alerts');
	}
	if($masterEnabled || (empty($old['summary_enabled']) && !empty($new['summary_enabled']))) {
		codi_debug_prime_channel('summary');
	}
	codi_debug_resync_schedules();

	wp_safe_redirect(add_query_arg(
		[ 'page' => 'codi-debug', 'updated' => '1' ],
		codi_debug_network_mode() ? network_admin_url('admin.php') : admin_url('options-general.php')
	));
	exit;
}

function codi_debug_settings_page() {
	if(!current_user_can(codi_debug_admin_capability())) return;
	$settings = codi_debug_get_settings();
	$intervals = codi_debug_interval_choices();
	$severities = codi_debug_min_severity_choices();
	$log = codi_debug_error_log_info();
	?>
	<div class="wrap">
		<h1>Codi Debug</h1>
		<?php if(isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php endif; ?>

		<p><a href="<?php echo esc_url(codi_debug_errors_url()); ?>">View recent PHP errors</a></p>

		<h2>PHP error log</h2>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="codi_debug_save_settings">
			<?php wp_nonce_field('codi_debug_save_settings'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="codi-debug-error-log-path">Custom error log path</label></th>
					<td>
						<input type="text" class="regular-text code" id="codi-debug-error-log-path" name="codi_debug[error_log_path]" value="<?php echo esc_attr($settings['error_log_path']); ?>" <?php echo defined('CODI_DEBUG_ERROR_LOG') ? 'readonly' : ''; ?>>
						<p class="description">Leave blank to auto-detect. Example: <code>/var/www/wordpress/logs/error.log</code>.<?php if(defined('CODI_DEBUG_ERROR_LOG')) : ?> The <code>CODI_DEBUG_ERROR_LOG</code> constant is defined and takes precedence.<?php endif; ?></p>
					</td>
				</tr>
			</table>
		<table class="widefat striped" style="max-width:900px;margin-bottom:24px;">
			<tbody>
				<tr><th style="width:180px;">Resolved path</th><td><code><?php echo esc_html($log['path'] ?: 'Not detected'); ?></code></td></tr>
				<tr><th>Source</th><td><?php echo esc_html($log['source'] ?: 'None'); ?></td></tr>
				<tr><th>File exists</th><td><?php echo $log['exists'] ? 'Yes' : 'No'; ?></td></tr>
				<tr><th>Readable</th><td><?php echo $log['readable'] ? 'Yes' : 'No'; ?></td></tr>
				<?php if($log['readable']) : ?>
					<tr><th>Size</th><td><?php echo esc_html(size_format($log['size'])); ?></td></tr>
					<tr><th>Last modified</th><td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $log['modified'])); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

			<h2>Notifications</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable notifications</th>
					<td><label><input type="checkbox" name="codi_debug[notifications_enabled]" value="1" <?php checked($settings['notifications_enabled']); ?>> Send PHP error notifications</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="codi-debug-recipient">Recipient</label></th>
					<td><input type="email" class="regular-text" id="codi-debug-recipient" name="codi_debug[recipient]" value="<?php echo esc_attr($settings['recipient']); ?>"></td>
				</tr>
			</table>

			<h2>Urgent alerts</h2>
			<p>Designed for breaking PHP failures. New matching errors are grouped into one email per check.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable urgent alerts</th>
					<td><label><input type="checkbox" name="codi_debug[alert_enabled]" value="1" <?php checked($settings['alert_enabled']); ?>> Check for urgent errors</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="codi-debug-alert-interval">Check frequency</label></th>
					<td><select id="codi-debug-alert-interval" name="codi_debug[alert_interval]">
						<?php foreach($intervals as $value => $meta) : ?>
							<option value="<?php echo esc_attr($value); ?>" <?php selected($settings['alert_interval'], $value); ?>><?php echo esc_html($meta['label']); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><label for="codi-debug-alert-severity">Minimum severity</label></th>
					<td><select id="codi-debug-alert-severity" name="codi_debug[alert_min_severity]">
						<?php foreach($severities as $value => $label) : ?>
							<option value="<?php echo esc_attr($value); ?>" <?php selected($settings['alert_min_severity'], $value); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
			</table>

			<h2>Periodic summary</h2>
			<p>The summary includes all new parsed PHP-log entries, grouped by error fingerprint.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable summary</th>
					<td><label><input type="checkbox" name="codi_debug[summary_enabled]" value="1" <?php checked($settings['summary_enabled']); ?>> Send an error summary when new entries exist</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="codi-debug-summary-interval">Summary frequency</label></th>
					<td><select id="codi-debug-summary-interval" name="codi_debug[summary_interval]">
						<?php foreach($intervals as $value => $meta) : ?>
							<option value="<?php echo esc_attr($value); ?>" <?php selected($settings['summary_interval'], $value); ?>><?php echo esc_html($meta['label']); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function codi_debug_errors_page() {
	if(!current_user_can(codi_debug_admin_capability())) return;
	$severity = sanitize_key($_GET['severity'] ?? '');
	$choices = codi_debug_severity_choices();
	if($severity !== '' && !isset($choices[$severity])) {
		$severity = '';
	}
	$entries = array_reverse(codi_debug_read_recent_entries());
	if($severity !== '') {
		$entries = array_values(array_filter($entries, function($entry) use($severity) {
			return ($entry['severity'] ?? 'critical') === $severity;
		}));
	}
	$total = count($entries);
	$perPage = 100;
	$page = max(1, (int) ($_GET['codi_debug_page'] ?? 1));
	$pages = max(1, (int) ceil($total / $perPage));
	$page = min($page, $pages);
	$visible = array_slice($entries, ($page - 1) * $perPage, $perPage);
	$log = codi_debug_error_log_info();
	?>
	<div class="wrap">
		<h1>PHP Error Log</h1>
		<p><a href="<?php echo esc_url(codi_debug_settings_url()); ?>">Codi Debug settings</a></p>

		<?php if(!$log['readable']) : ?>
			<div class="notice notice-warning"><p>No readable PHP error log could be detected. Set a custom path in Codi Debug settings, or leave it blank to auto-detect PHP's configured <code>error_log</code> and then <code>WP_DEBUG_LOG</code>. The <code>CODI_DEBUG_ERROR_LOG</code> constant and <code>codi_debug_error_log_path</code> filter can still override the detected path.</p></div>
		<?php else : ?>
			<p>Source: <code><?php echo esc_html($log['path']); ?></code>. Showing parsed entries from the most recent log data; this screen never clears or modifies the log.</p>

			<form method="get" style="margin:16px 0;">
				<input type="hidden" name="page" value="codi-debug-errors">
				<label for="codi-debug-severity"><strong>Severity</strong></label>
				<select id="codi-debug-severity" name="severity">
					<option value="">All severities</option>
					<?php foreach($choices as $value => $label) : ?>
						<option value="<?php echo esc_attr($value); ?>" <?php selected($severity, $value); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button('Filter', 'secondary', '', false); ?>
			</form>

			<p><?php echo esc_html(number_format_i18n($total)); ?> matching recent entries.</p>
			<table class="widefat striped">
				<thead><tr><th style="width:180px;">Time</th><th style="width:190px;">Context</th><th>Error</th></tr></thead>
				<tbody>
				<?php if(!$visible) : ?>
					<tr><td colspan="3">No matching errors found in the recent log data.</td></tr>
				<?php else : foreach($visible as $entry) :
					$lines = preg_split('/\r\n|\n|\r/', $entry['message']);
					$first = array_shift($lines);
				?>
					<tr>
						<td><?php echo esc_html($entry['timestamp'] ?: '—'); ?></td>
						<td>
							<strong><?php echo esc_html(strtoupper($entry['severity'])); ?></strong>
							<?php if(codi_debug_network_mode()) : ?><br><span><?php echo esc_html($entry['site'] ?? 'Unknown'); ?></span><?php endif; ?>
						</td>
						<td>
							<?php echo esc_html($first); ?>
							<?php if($lines) : ?><details style="margin-top:6px;"><summary>Trace / details</summary><pre style="white-space:pre-wrap;max-width:1100px;overflow:auto;"><?php echo esc_html(implode("\n", $lines)); ?></pre></details><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if($pages > 1) : ?>
				<p class="tablenav-pages" style="margin-top:16px;">
				<?php for($i = 1; $i <= $pages; $i++) :
					$url = add_query_arg([ 'severity' => $severity, 'codi_debug_page' => $i ], codi_debug_errors_url());
				?>
					<a class="button <?php echo $i === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($i); ?></a>
				<?php endfor; ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}