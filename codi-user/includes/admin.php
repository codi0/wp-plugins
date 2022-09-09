<?php

//register admin page
function codi_user_admin_menu() {
	//set vars
	$page = CODI_USER_PLUGIN_NAME;
	$path = explode('/plugins/', CODI_USER_PLUGIN_FILE)[1];
	//register menu option
	add_theme_page(__('User frontend'), __('User frontend'), 'manage_options', $page, 'codi_user_admin_options');
	//register settings link
	add_filter('plugin_action_links_' . $path, 'codi_user_admin_link');
}

//display admin options
function codi_user_admin_options() {
	//set vars
	$page = CODI_USER_PLUGIN_NAME;
	$captcha = get_option('codi_captcha', [ 'site_key' => '', 'secret_key' => '' ]);
	//save data
	if(isset($_POST['urls']) && check_admin_referer($page)) {
		//format data
		$urls = array_map('intval', (array) $_POST['urls']);
		$captcha = array_map('sanitize_text_field', (array) $_POST['captcha']);
		//store in database
		update_option('codi_user_urls', $urls);
		update_option('codi_captcha', $captcha);
	}
	//generate html
	echo '<div class="wrap">' . "\n";
	echo '<h2>' . __('User frontend') . ' <small>(by <a href="https://github.com/codi-si/wp" target="_blank">codi0</a>)</small></h2>' . "\n";
	echo '<p>Create <a href="edit.php?post_type=page">WordPress pages</a>, add the relevant shortcode to each one and set the URL mapping.</p>';
	echo '<form method="post">' . "\n";
	wp_nonce_field($page);
	echo '<table class="wp-list-table widefat striped">';
	echo '<thead>';
	echo '<tr><th><b>Page</b></th><th><b>Shortcode</b></th><th><b>URL Mapping</b></th></tr>';
	echo '</thead>';
	echo '<tbody>';
	echo '<tr><td>Login</td><td>[codi_login title="Login"]</td><td>' . codi_user_admin_select('login') . '</td></tr>';
	echo '<tr><td>Logout</td><td>[codi_logout]</td><td>' . codi_user_admin_select('logout') . '</td></tr>';
	echo '<tr><td>Forgotten password</td><td>[codi_password title="Forgotten password"]</td><td>' . codi_user_admin_select('password') . '</td></tr>';
	echo '<tr><td>Regsiter</td><td>[codi_register title="Register" password="true" captcha="true"]</td><td>' . codi_user_admin_select('register') . '</td></tr>';
	echo '<tr><td>Edit profile</td><td>[codi_profile title="Edit your profile"]</td><td>' . codi_user_admin_select('profile') . '</td></tr>';
	echo '</tbody>';
	echo '</table>';
	echo '<p style="margin-bottom:30px;"><input type="submit" class="button button-primary" value="' . __('Save mapping') . '"></p>' . "\n";
	echo '<h3>Captcha settings</h3>' . "\n";
	echo '<p>To use the captcha, create a <a href="https://www.google.com/recaptcha/admin/create" target="_blank">reCaptcha key pair</a> (v2, tickbox) and paste the keys below.</p>' . "\n";
	echo '<table class="form-table">' . "\n";
	echo '<tr><th>Site key</th><td><input type="text" name="captcha[site_key]" size="50" value="' . esc_attr($captcha['site_key']) . '"></td></tr>' . "\n";
	echo '<tr><th>Secret key</th><td><input type="text" name="captcha[secret_key]" size="50" value="' . esc_attr($captcha['secret_key']) . '"></td></tr>' . "\n";
	echo '</table>' . "\n";
	echo '<p style="margin-bottom:30px;"><input type="submit" class="button button-primary" value="' . __('Save captcha') . '"></p>' . "\n";
	echo '<h3>Add custom fields</h3>' . "\n";
	echo '<p>Use "codi_user_extend" to add new fields to any of the forms, in your theme\'s functions.php file</p>' . "\n";
	echo '<p><b>An example</b></p>' . "\n";
	echo '<pre>' . "\n";
	echo '<code>';
	echo htmlspecialchars('<?php
codi_user_extend("organisation", [
	"label" => "Your organisation",
	"forms" => [ "register", "profile" ], //Options are login, password, register, profile
	"admin" => NULL, //NULL = show in admin and frontend, FALSE = show in frontend only, TRUE = show in admin only
	"position" => 10, //The higher the number, the further down the form the field will display
	"render" => function($name, $value, $user) {
		echo \'<input type="text" name="{name}" id="{name}" value="{value}">\';
	},
	"validate" => function($name, $value, $user, $errors) {
		if(!$value) {
			$errors->add($name, __("<strong>Error</strong>: please enter the name of your organisation."));
		}
	},
	"save" => function($name, $value, $user) {
		//Saving happens automatically by default, as user meta data
		//Unless you want to over-ride the default behaviour, remove this "save" callback completely
	}
]);');
	echo '</code>' . "\n";
	echo '</pre>' . "\n";
	echo '</form>' . "\n";
	echo '</div>' . "\n";
}

//display select menu
function codi_user_admin_select($name) {
	//set vars
	$html = '';
	$urls = get_option('codi_user_urls') ?: [];
	//build menu
	$html .= '<select name="urls[' . $name . ']" id="user_urls_' . $name . '">';
	$html .= '<option value="">Not mapped</option>';
	foreach(get_pages() as $page) {
		//set page vars
		$before = $page->post_parent ? '-- ' : '';
		$selected = (isset($urls[$name]) && $urls[$name] == $page->ID) ? ' selected' : '';
		//add page
		$html .= '<option value="' . esc_attr($page->ID) . '"' . $selected . '>' . $before . esc_html($page->post_title) . '</option>';
	}
	$html .= '</select>';
	//return
	return $html;
}

//display plugin settings link
function codi_user_admin_link($links) {
	//set vars
	$page = CODI_USER_PLUGIN_NAME;
	//create link
	$links[] = '<a href="themes.php?page=' . esc_attr($page) . '">' . __('Settings') . '</a>';
	//return
	return $links;
}

//init
add_action('admin_menu', 'codi_user_admin_menu');