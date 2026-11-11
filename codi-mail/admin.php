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
		$html  = '<tr><td>About</td><td style="font-size:0.9em; font-style:italic;">Send through the Amazon SES API when SMTP is unavailable or undesirable.</td></tr>' . "\n";
		$html .= '<tr><td>API Version</td><td>v2</td></tr>' . "\n";
		$html .= '<tr><td>Region</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
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

function codi_mail_admin_options() {
	if(!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have permission to manage these settings.'));
	}

	$page = CODI_MAIL_PLUGIN_NAME;
	$opts = codi_mail_opts();
	$types = [ 'smtp' => 'SMTP', 'ses' => 'Amazon SES API' ];
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
	}

	$fields = apply_filters('codi_mail_admin_fields', codi_mail_admin_fields($opts), $opts);

	echo '<div class="wrap">' . "\n";
	echo '<h1>' . esc_html__('Codi Mail') . '</h1>' . "\n";
	echo '<p>Send WordPress email through SMTP or the Amazon SES API.</p>' . "\n";
	echo '<form name="mail_opts" method="post" action="#mail_opts">' . "\n";
	wp_nonce_field($page);
	echo '<table class="form-table">' . "\n";
	echo '<tr><td width="120">Type</td><td>' . codi_mail_admin_dropdown('mail_opts[type]', $opts['type'], $types) . '</td></tr>' . "\n";
	echo $fields;
	echo '<tr><td>Default From email</td><td><input type="email" name="mail_opts[from]" size="50" value="' . esc_attr($opts['from']) . '"></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<br>' . "\n";
	echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Save Changes') . '">' . "\n";
	echo '</form>' . "\n";

	echo '<h2 id="mail_test" style="margin-top:50px;">Send test email</h2>' . "\n";
	echo '<form name="test_mail" method="post" action="#mail_test" enctype="multipart/form-data">' . "\n";
	wp_nonce_field($page);

	if($test['result'] !== null) {
		echo '<br>' . "\n";
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
	echo '<br>' . "\n";
	echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Send test') . '">' . "\n";
	echo '</form>' . "\n";
	echo '</div>' . "\n";

	$url = add_query_arg('page', $page, admin_url('options-general.php'));
	echo '<script>' . "\n";
	echo 'jQuery("#mail_opts_type").on("change", function() {' . "\n";
	echo 'window.location.href = ' . wp_json_encode($url . '&type=') . ' + encodeURIComponent(this.value);' . "\n";
	echo '});' . "\n";
	echo '</script>' . "\n";
}

function codi_mail_admin_link($links) {
	$url = add_query_arg('page', CODI_MAIL_PLUGIN_NAME, admin_url('options-general.php'));
	$links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings') . '</a>';
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
