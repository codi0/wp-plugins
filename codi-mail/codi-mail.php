<?php

/*
Plugin Name: Codi Mail
Description: Send WordPress emails using an SMTP email provider.
Version: 1.0.0
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') or die;


/*
https://console.aws.amazon.com/ses/
- SMTP settings - generate username and password
- Sending statistics - to move account out of sandbox
- Domains - to add and verify sending domains
--- DKIM
--- Mail From
*/

//define constants
define('CODI_MAIL_PLUGIN_FILE', __FILE__);
define('CODI_MAIL_PLUGIN_NAME', basename(__DIR__));


/* MAILER */

//get or set mail options
function codi_mail_opts(array $opts = null) {
	//set vars
	$defaults = [
		'type' => 'smtp',
		'host' => '',
		'port' => 587,
		'protocol' => 'tls',
		'username' => '',
		'password' => '',
		'from' => get_bloginfo('admin_email'),
	];
	//update db?
	if(is_array($opts)) {
		//remove from?
		if(isset($opts['from']) && $opts['from'] === get_bloginfo('admin_email')) {
			unset($opts['from']);
		}
		//filter
		$opts = apply_filters(__FUNCTION__, $opts);
		//sanitize
		$opts = array_map('sanitize_text_field', $opts);
		//store
		update_option(__FUNCTION__, $opts);
	} else {
		//get opts
		$opts = get_option(__FUNCTION__, []);
	}
	//return
	return array_merge($defaults, $opts);
}

//load mail driver
function codi_mail_load_driver($driver=null) {
	//get opts
	$opts = codi_mail_opts();
	//set driver
	$driver = $driver ?: $opts['type'];
	//has driver?
	if(empty($driver)) {
		return;
	}
	//get file path
	$file = __DIR__ . '/driver/' . $driver . '.php';
	//load driver?
	if(is_file($file)) {
		include_once($file);
	}
}

//phpmailer hook
function codi_mail_init_phpmailer($phpMailer) {
	//set vars
	$opts = codi_mail_opts();
	//is smtp?
	if($opts['type'] !== 'smtp') {
		return;
	}
	//continue?
	if($opts['host']) {
		//use SMTP
		$phpMailer->isSMTP();
		$phpMailer->SMTPAuth = true;
		//set credentials
		$phpMailer->Host = $opts['host'];
		$phpMailer->Port = $opts['port'] ?: 587;
		$phpMailer->Username = $opts['username'];
		$phpMailer->Password = $opts['password'];
		//set protocol?
		if($opts['protocol']) {
			$phpMailer->SMTPSecure = $opts['protocol'];
		}
		//set from?
		if($opts['from']) {
			$phpMailer->setFrom($opts['from']);
		}
	}
}

//mailer hooks
add_action('plugins_loaded', 'codi_mail_load_driver', -9999);
add_action('phpmailer_init', 'codi_mail_init_phpmailer', 9999);


/* ADMIN */

//register admin page
function codi_mail_admin_menu() {
	//set vars
	$page = CODI_MAIL_PLUGIN_NAME;
	$path = explode('/plugins/', CODI_MAIL_PLUGIN_FILE)[1];
	//register menu option
	add_options_page(__('SMTP Mail'), __('SMTP Mail'), 'manage_options', $page, 'codi_mail_admin_options');
	//register settings link
	add_filter('plugin_action_links_' . $path, 'codi_mail_admin_link');
}

//display admin options
function codi_mail_admin_options() {
	//set vars
	$menu = '';
	$page = CODI_MAIL_PLUGIN_NAME;
	//get data
	$opts = codi_mail_opts();
	$types = [ 'smtp' => 'SMTP', 'ses' => 'Amazon SES API' ];
	$test = [ 'to' => '', 'subject' => '', 'message' => '', 'result' => null ];
	//force type?
	if(isset($_GET['type']) && $_GET['type']) {
		$opts['type'] = $_GET['type'];
	}
	//load driver
	codi_mail_load_driver($opts['type']);
	//save data?
	if(isset($_POST['mail_opts']) && check_admin_referer($page)) {
		$opts = codi_mail_opts($_POST['mail_opts']);
	}
	//send test?
	if(isset($_POST['mail_test']) && check_admin_referer($page)) {
		$test = $_POST['mail_test'];
		$test['result'] = wp_mail($test['to'], $test['subject'], $test['message']);
	}
	//default fields
	$fields  = '<tr><td>About</td><td style="font-size:0.9em; font-style:italic;">Not sure which provider to choose? Give Amazon SES a try: <a href="https://blog.mailtrap.io/amazon-ses-explained/#Step-by-Step_setup" target="_blank">Amazon SES setup guide</a></td></tr>' . "\n";
	$fields .= '<tr><td>Host</td><td><input type="text" name="mail_opts[host]" size="50" value="' . esc_attr($opts['host']) . '"></td></tr>' . "\n";
	$fields .= '<tr><td>Port</td><td><input type="text" name="mail_opts[port]" size="5" value="' . esc_attr($opts['port']) . '"></td></tr>' . "\n";
	$fields .= '<tr><td>Protocol</td><td><input type="text" name="mail_opts[protocol]" size="5" value="' . esc_attr($opts['protocol']) . '"></td></tr>' . "\n";
	$fields .= '<tr><td>Username</td><td><input type="text" name="mail_opts[username]" size="50" value="' . esc_attr($opts['username']) . '"></td></tr>' . "\n";
	$fields .= '<tr><td>Password</td><td><input type="password" name="mail_opts[password]" size="50" value="' . esc_attr($opts['password']) . '"></td></tr>' . "\n";
	//generate html
	echo '<div class="wrap">' . "\n";
	echo '<h2>' . __('SMTP Mail') . '</h2>' . "\n";
	echo '<p>Improve email deliverabilty by sending them through an SMTP server. Add your settings below, then send a test email to verify.</p>' . "\n";
	echo '<form name="mail_opts" method="post" action="#mail_opts">' . "\n";
	wp_nonce_field($page);
	echo '<table class="form-table">' . "\n";
	echo '<tr><td width="120">Type</td><td>' . codi_mail_admin_dropdown('mail_opts[type]', $opts['type'], $types) . '</td></tr>' . "\n";
	echo apply_filters('codi_mail_admin_fields', $fields, $opts);
	echo '<tr><td>From email</td><td><input type="text" name="mail_opts[from]" size="50" value="' . esc_attr($opts['from']) . '"></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<br>' . "\n";
	echo '<input type="submit" class="button button-primary" value="' . __('Save Changes') . '">' . "\n";
	echo '</form>' . "\n";
	echo '<h3 id="mail_test" style="margin:50px 0 0 0;">Send test email</h3>' . "\n";
	echo '<form name="test_mail" method="post" action="#mail_test">' . "\n";
	wp_nonce_field($page);
	//show result?
	if($test['result'] !== null) {
		echo '<br>' . "\n";
		if($test['result']) {
			echo '<div class="notice notice-success inline">Test email successfully sent.</div>';
		} else {
			if(!$test['to']) {
				echo '<div class="notice notice-error inline">Please enter an email address in the To field.</div>';
			} else {
				echo '<div class="notice notice-error inline">Test email failed to send. Please check your email settings.</div>';
			}
		}
	}
	echo '<table class="form-table">' . "\n";
	echo '<tr><td width="120">To</td><td><input type="text" name="mail_test[to]" size="50" value="' . esc_attr($test['to']) . '"></td></tr>' . "\n";
	echo '<tr><td>Subject</td><td><input type="text" name="mail_test[subject]" size="50" value="' . esc_attr($test['subject']) . '"></td></tr>' . "\n";
	echo '<tr><td>Message</td><td><textarea name="mail_test[message]" rows="5" cols="53">' . esc_html($test['message']) . '</textarea></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<br>' . "\n";
	echo '<input type="submit" class="button button-primary" value="' . __('Send test') . '">' . "\n";
	echo '</form>' . "\n";
	echo '</div>' . "\n";
	echo '<script>' . "\n";
	echo 'jQuery("#mail_opts_type").on("change", function(e) {' . "\n";
	echo '	location.href = "?page=' . $page . '&type=" + this.value;' . "\n";
	echo '})' . "\n";
	echo '</script>' . "\n";
}

//display plugin settings link
function codi_mail_admin_link($links) {
	//set vars
	$page = CODI_MAIL_PLUGIN_NAME;
	//create link
	$links[] = '<a href="admin.php?page=' . esc_attr($page) . '">' . __('Settings') . '</a>';
	//return
	return $links;
}

//admin dropdown menu
function codi_mail_admin_dropdown($name, $value, array $opts) {
	//set vars
	$menu = '';
	$id = trim(str_replace([ '[', ']' ], '_', $name), '_');
	//open menu
	$menu .= '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '">';
	//loop through array
	foreach($opts as $k => $v) {
		$selected = ($k == $value) ? ' selected' : '';
		$menu .= '<option value="' . esc_attr($k) . '"' . $selected . '>' . esc_html($v) . '</option>';
	}
	//close menu
	$menu .= '</select>';
	//return
	return $menu;
}

//admin hooks
add_action('admin_menu', 'codi_mail_admin_menu');