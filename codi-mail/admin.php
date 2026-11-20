<?php

defined('ABSPATH') || exit;

function codi_mail_admin_menu() {
	add_options_page(
		__('Codi Mail'),
		__('Codi Mail'),
		'manage_options',
		CODI_MAIL_PLUGIN_NAME,
		'codi_mail_admin_options'
	);
}

function codi_mail_admin_fields(array $opts) {
	if($opts['type'] === 'ses') {
		$html  = '<tr><td>Region</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
		$html .= '<tr><td>Access key ID</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
		$html .= '<tr><td>Secret access key</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
		return $html;
	}

	$html  = '<tr><td>Host</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Port</td><td><input type="number" min="1" max="65535" name="mail_opts[port]" size="5" value="' . esc_attr($opts['port']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Protocol</td><td>' . codi_mail_admin_dropdown('mail_opts[protocol]', $opts['protocol'], [
		'tls' => 'TLS',
		'ssl' => 'SSL',
		'' => 'None',
	]) . '</td></tr>' . "\n";
	$html .= '<tr><td>Username</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
	$html .= '<tr><td>Password</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
	return $html;
}

function codi_mail_admin_page_url($tab = 'outbound') {
	return add_query_arg([
		'page' => CODI_MAIL_PLUGIN_NAME,
		'tab' => $tab,
	], admin_url('options-general.php'));
}

function codi_mail_admin_render_outbound($page, array $opts, array $types, array $test) {
	$fields = apply_filters('codi_mail/admin_fields', codi_mail_admin_fields($opts), $opts);

	echo '<p>Send WordPress email through SMTP or the Amazon SES API.</p>' . "\n";
	echo '<form name="mail_opts" method="post" action="' . esc_url(codi_mail_admin_page_url('outbound')) . '">' . "\n";
	wp_nonce_field($page);
	echo '<table class="form-table">' . "\n";
	echo '<tr><td width="120">Type</td><td>' . codi_mail_admin_dropdown('mail_opts[type]', $opts['type'], $types) . '</td></tr>' . "\n";
	echo $fields;
	echo '<tr><td>Default From email</td><td><input type="email" name="mail_opts[from]" size="50" value="' . esc_attr($opts['from']) . '"></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__('Save Changes') . '"></p>' . "\n";
	echo '</form>' . "\n";

	echo '<hr style="margin:35px 0;">' . "\n";
	echo '<h2 id="mail_test">Send test email</h2>' . "\n";
	echo '<form name="test_mail" method="post" action="' . esc_url(codi_mail_admin_page_url('outbound') . '#mail_test') . '" enctype="multipart/form-data">' . "\n";
	wp_nonce_field($page);

	if($test['result'] !== null) {
		if($test['result']) {
			echo '<div class="notice notice-success inline"><p>Test email successfully sent.</p></div>';
		} elseif($test['to'] === '') {
			echo '<div class="notice notice-error inline"><p>Please enter an email address in the To field.</p></div>';
		} elseif($test['error'] !== '') {
			echo '<div class="notice notice-error inline"><p>Test email failed: ' . esc_html($test['error']) . '</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p>Test email failed to send. Please check your email settings.</p></div>';
		}
	}

	echo '<table class="form-table">' . "\n";
	echo '<tr><td width="120">To</td><td><input type="email" multiple name="mail_test[to]" size="50" value="' . esc_attr($test['to']) . '"></td></tr>' . "\n";
	echo '<tr><td>Subject</td><td><input type="text" name="mail_test[subject]" size="50" value="' . esc_attr($test['subject']) . '"></td></tr>' . "\n";
	echo '<tr><td>Message</td><td><textarea name="mail_test[message]" rows="5" cols="53">' . esc_textarea($test['message']) . '</textarea></td></tr>' . "\n";
	echo '<tr><td>Attachment 1</td><td><input type="file" name="mail_test_attachments[]"></td></tr>' . "\n";
	echo '<tr><td>Attachment 2</td><td><input type="file" name="mail_test_attachments[]"></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__('Send test') . '"></p>' . "\n";
	echo '</form>' . "\n";

	$url = codi_mail_admin_page_url('outbound');
	echo '<script>' . "\n";
	echo 'jQuery("#mail_opts_type").on("change", function() {' . "\n";
	echo 'window.location.href = ' . wp_json_encode($url . '&type=') . ' + encodeURIComponent(this.value);' . "\n";
	echo '});' . "\n";
	echo '</script>' . "\n";
}

function codi_mail_admin_render_inbound($page, array $accounts, array $health, $message = '', $error = '') {
	echo '<p>Receive mail from one or more provider mailboxes and dispatch a normalized <code>codi_mail/inbound_message</code> event for consuming plugins.</p>' . "\n";

	if($message !== '') {
		echo '<div class="notice notice-success inline"><p>' . esc_html($message) . '</p></div>';
	}
	if($error !== '') {
		echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
	}

	echo '<form method="post" action="' . esc_url(codi_mail_admin_page_url('inbound')) . '" style="margin:20px 0;">' . "\n";
	wp_nonce_field($page);
	echo '<input type="hidden" name="inbound_add" value="1">' . "\n";
	echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Add mailbox') . '">' . "\n";
	echo '</form>' . "\n";

	if(!$accounts) {
		echo '<p>No inbound mailboxes are configured.</p>' . "\n";
		return;
	}

	$providers = codi_mail_inbound_providers();
	foreach($accounts as $id => $account) {
		$accountHealth = isset($health[$id]) && is_array($health[$id]) ? $health[$id] : [];
		$title = trim((string)$account['label']);
		if($title === '') {
			$title = $account['mailbox'] !== '' ? $account['mailbox'] : 'Inbound mailbox';
		}

		echo '<div class="card" style="max-width:900px; margin:0 0 22px; padding:18px 22px;">' . "\n";
		echo '<h2 style="margin-top:0;">' . esc_html($title) . '</h2>' . "\n";
		echo '<form method="post" action="' . esc_url(codi_mail_admin_page_url('inbound')) . '">' . "\n";
		wp_nonce_field($page);
		echo '<input type="hidden" name="inbound_account_id" value="' . esc_attr($id) . '">' . "\n";
		echo '<table class="form-table" style="margin-top:0;">' . "\n";
		echo '<tr><td width="140">Label</td><td><input type="text" name="inbound_account[label]" size="50" value="' . esc_attr($account['label']) . '" placeholder="e.g. BHI CRM"></td></tr>' . "\n";
		echo '<tr><td>Enabled</td><td><label><input type="checkbox" name="inbound_account[enabled]" value="1"' . checked(!empty($account['enabled']), true, false) . '> Poll this mailbox</label></td></tr>' . "\n";
		echo '<tr><td>Provider</td><td>' . codi_mail_admin_dropdown('inbound_account[provider]', $account['provider'], $providers) . '</td></tr>' . "\n";
		echo '<tr><td>Tenant ID</td><td><input type="text" name="inbound_account[tenant_id]" size="50" value="' . esc_attr($account['tenant_id']) . '"></td></tr>' . "\n";
		echo '<tr><td>Client ID</td><td><input type="text" name="inbound_account[client_id]" size="50" value="' . esc_attr($account['client_id']) . '"></td></tr>' . "\n";
		echo '<tr><td>Client secret</td><td><input type="password" name="inbound_account[client_secret]" size="50" value="" autocomplete="new-password"><p class="description">Leave blank to retain the saved secret.</p></td></tr>' . "\n";
		echo '<tr><td>Mailbox</td><td><input type="email" name="inbound_account[mailbox]" size="50" value="' . esc_attr($account['mailbox']) . '" placeholder="mailbox@example.com"></td></tr>' . "\n";
		echo '<tr><td>Page size</td><td><input type="number" min="1" max="100" name="inbound_account[page_size]" value="' . esc_attr($account['page_size']) . '"></td></tr>' . "\n";
		echo '</table>' . "\n";
		echo '<p><button type="submit" name="inbound_save" value="1" class="button button-primary">Save mailbox</button></p>' . "\n";
		echo '</form>' . "\n";

		echo '<div style="display:flex; gap:8px; align-items:center; margin-top:12px;">' . "\n";
		echo '<form method="post" action="' . esc_url(codi_mail_admin_page_url('inbound')) . '">' . "\n";
		wp_nonce_field($page);
		echo '<input type="hidden" name="inbound_check" value="' . esc_attr($id) . '">' . "\n";
		echo '<button type="submit" class="button"' . (empty($account['enabled']) ? ' disabled' : '') . '>Check now</button>' . "\n";
		echo '</form>' . "\n";

		echo '<form method="post" action="' . esc_url(codi_mail_admin_page_url('inbound')) . '">' . "\n";
		wp_nonce_field($page);
		echo '<input type="hidden" name="inbound_remove" value="' . esc_attr($id) . '">' . "\n";
		echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Remove this inbound mailbox configuration?\');">Remove</button>' . "\n";
		echo '</form>' . "\n";
		echo '</div>' . "\n";

		echo '<p class="description" style="margin-top:12px;">';
		echo 'Last successful check: ' . esc_html($accountHealth['last_success_at'] ?? 'Never') . '<br>';
		echo 'Last dispatched message: ' . esc_html($accountHealth['last_dispatched_at'] ?? 'Never');
		$failures = (int)($accountHealth['consecutive_failures'] ?? 0);
		if($failures > 0) {
			echo '<br>Consecutive failures: ' . esc_html((string)$failures);
		}
		if(!empty($accountHealth['last_error'])) {
			echo '<br>Last error: ' . esc_html($accountHealth['last_error']);
		}
		echo '</p>' . "\n";
		echo '</div>' . "\n";
	}
}

function codi_mail_admin_options() {
	if(!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have permission to manage these settings.'));
	}

	$page = CODI_MAIL_PLUGIN_NAME;
	$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'outbound';
	if(!in_array($tab, [ 'outbound', 'inbound' ], true)) {
		$tab = 'outbound';
	}

	$opts = codi_mail_opts();
	$types = [ 'smtp' => 'SMTP', 'ses' => 'Amazon SES API' ];
	$accounts = codi_mail_inbound_accounts();
	$health = codi_mail_inbound_health();
	$notice = '';
	$inboundError = '';
	$test = [ 'to' => '', 'subject' => '', 'message' => '', 'result' => null, 'error' => '' ];

	if(isset($_GET['type'])) {
		$type = sanitize_key(wp_unslash($_GET['type']));
		if(isset($types[$type])) {
			$opts['type'] = $type;
		}
	}

	if(isset($_POST['mail_opts']) && is_array($_POST['mail_opts']) && check_admin_referer($page)) {
		$opts = codi_mail_opts(wp_unslash($_POST['mail_opts']));
	}

	if(isset($_POST['inbound_add']) && check_admin_referer($page)) {
		$id = codi_mail_inbound_account_id();
		$accounts[$id] = codi_mail_inbound_account_defaults($id);
		$accounts = codi_mail_inbound_accounts($accounts);
		$notice = 'Inbound mailbox added.';
		$tab = 'inbound';
	}

	if(
		isset($_POST['inbound_save'], $_POST['inbound_account_id'], $_POST['inbound_account'])
		&& is_array($_POST['inbound_account'])
		&& check_admin_referer($page)
	) {
		$id = sanitize_key(wp_unslash($_POST['inbound_account_id']));
		if(isset($accounts[$id])) {
			$accounts[$id] = codi_mail_inbound_sanitize_account(
				wp_unslash($_POST['inbound_account']),
				$accounts[$id],
				$id
			);
			$accounts = codi_mail_inbound_accounts($accounts);
			$notice = 'Inbound mailbox saved.';
		}
		$tab = 'inbound';
	}

	if(isset($_POST['inbound_remove']) && check_admin_referer($page)) {
		codi_mail_inbound_remove_account(wp_unslash($_POST['inbound_remove']));
		$accounts = codi_mail_inbound_accounts();
		$health = codi_mail_inbound_health();
		$notice = 'Inbound mailbox removed.';
		$tab = 'inbound';
	}

	if(isset($_POST['inbound_check']) && check_admin_referer($page)) {
		$id = sanitize_key(wp_unslash($_POST['inbound_check']));
		$result = codi_mail_inbound_poll($id);
		$health = codi_mail_inbound_health();
		if(is_wp_error($result)) {
			$inboundError = $result->get_error_message();
		} elseif(!empty($result['locked'])) {
			$notice = 'Mailbox check already in progress.';
		} else {
			$notice = sprintf(
				'Mailbox checked. %d message(s) dispatched.',
				(int)($result['dispatched'] ?? 0)
			);
		}
		$tab = 'inbound';
	}

	if(isset($_POST['mail_test']) && is_array($_POST['mail_test']) && check_admin_referer($page)) {
		$posted = wp_unslash($_POST['mail_test']);
		$test['to'] = isset($posted['to']) ? (string)$posted['to'] : '';
		$test['subject'] = isset($posted['subject']) ? (string)$posted['subject'] : '';
		$test['message'] = isset($posted['message']) ? (string)$posted['message'] : '';

		$attachments = [];
		$attachment_files = [];
		$attachment_dirs = [];

		if(!empty($_FILES['mail_test_attachments']['tmp_name']) && is_array($_FILES['mail_test_attachments']['tmp_name'])) {
			foreach($_FILES['mail_test_attachments']['tmp_name'] as $i => $tmp_name) {
				if($i >= 2) {
					break;
				}

				$error = $_FILES['mail_test_attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
				if(empty($tmp_name) || !is_uploaded_file($tmp_name) || (int)$error !== UPLOAD_ERR_OK) {
					continue;
				}

				$name = sanitize_file_name($_FILES['mail_test_attachments']['name'][$i] ?? '');
				if($name === '') {
					continue;
				}

				$dir = trailingslashit(get_temp_dir()) . 'codi-mail-test-' . wp_generate_uuid4();
				if(!wp_mkdir_p($dir)) {
					continue;
				}

				$path = trailingslashit($dir) . $name;
				if(move_uploaded_file($tmp_name, $path)) {
					$attachments[] = $path;
					$attachment_files[] = $path;
					$attachment_dirs[] = $dir;
				} else {
					rmdir($dir);
				}
			}
		}

		$mail_error = '';
		$error_listener = function($error) use (&$mail_error) {
			if(is_wp_error($error)) {
				$mail_error = $error->get_error_message();
			}
		};

		add_action('wp_mail_failed', $error_listener, 9999);

		try {
			$test['result'] = wp_mail($test['to'], $test['subject'], $test['message'], [], $attachments);
		} catch(Throwable $e) {
			$test['result'] = false;
			$mail_error = $e->getMessage();
		} finally {
			remove_action('wp_mail_failed', $error_listener, 9999);

			foreach($attachment_files as $file) {
				if(is_file($file)) {
					unlink($file);
				}
			}

			foreach($attachment_dirs as $dir) {
				if(is_dir($dir)) {
					rmdir($dir);
				}
			}
		}

		$test['error'] = $mail_error;
		$tab = 'outbound';
	}

	echo '<div class="wrap">' . "\n";
	echo '<h1>' . esc_html__('Codi Mail') . '</h1>' . "\n";
	echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">' . "\n";
	echo '<a class="nav-tab' . ($tab === 'outbound' ? ' nav-tab-active' : '') . '" href="' . esc_url(codi_mail_admin_page_url('outbound')) . '">Outbound</a>' . "\n";
	echo '<a class="nav-tab' . ($tab === 'inbound' ? ' nav-tab-active' : '') . '" href="' . esc_url(codi_mail_admin_page_url('inbound')) . '">Inbound</a>' . "\n";
	echo '</nav>' . "\n";

	if($tab === 'inbound') {
		codi_mail_admin_render_inbound($page, $accounts, $health, $notice, $inboundError);
	} else {
		codi_mail_admin_render_outbound($page, $opts, $types, $test);
	}

	echo '</div>' . "\n";
}

function codi_mail_admin_link($links) {
	$links[] = '<a href="' . esc_url(codi_mail_admin_page_url('outbound')) . '">' . esc_html__('Settings') . '</a>';
	return $links;
}

function codi_mail_admin_dropdown($name, $value, array $opts) {
	$id = trim(str_replace([ '[', ']' ], '_', $name), '_');
	$menu = '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '">';

	foreach($opts as $key => $label) {
		$menu .= '<option value="' . esc_attr($key) . '"' . selected($key, $value, false) . '>' . esc_html($label) . '</option>';
	}

	return $menu . '</select>';
}

add_action('admin_menu', 'codi_mail_admin_menu');
add_filter('plugin_action_links_' . plugin_basename(CODI_MAIL_PLUGIN_FILE), 'codi_mail_admin_link');
