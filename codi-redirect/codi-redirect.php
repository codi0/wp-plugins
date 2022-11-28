<?php

/*
Plugin Name: Codi Redirect
Description: Redirect any WordPress url to any other url.
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_REDIRECT_PLUGIN_FILE', __FILE__);
define('CODI_REDIRECT_PLUGIN_NAME', basename(__DIR__));

//process redirects
function codi_redirect_process() {
	//get current url
	$uri = $_SERVER['REQUEST_URI'];
	//get data
	$redirects = codi_redirect_data();
	$redirects = explode("\n", $redirects ?: '');
	//loop through redirects
	foreach($redirects as $r) {
		//split redirect parts
		$r = preg_split('/\s+/', $r);
		//valid redirect?
		if(count($r) === 3) {
			//format response code
			$code = is_numeric($r[0]) ? intval($r[0]) : 302;
			//format regex
			$regex = str_replace([ '/', '\\/' ], '\/', $r[1]);
			//add ending?
			if(strpos($regex, '$') === false) {
				$regex .= '(\/)?$';
			}
			//match found?
			if(preg_match('/' . $regex . '/', $uri)) {
				//set response code
				http_response_code($code);
				//redirect user
				header("Location: " . $r[2]);
				exit();
			}
		}
	}
}

//register admin page
function codi_redirect_admin_menu() {
	//set vars
	$page = CODI_REDIRECT_PLUGIN_NAME;
	$path = explode('/plugins/', CODI_REDIRECT_PLUGIN_FILE)[1];
	//register menu option
	add_options_page(__('Redirects'), __('Redirects'), 'manage_options', $page, 'codi_redirect_admin_options');
	//register settings link
	add_filter('plugin_action_links_' . $path, 'codi_redirect_admin_link');
}

//display admin options
function codi_redirect_admin_options() {
	//set vars
	$page = CODI_REDIRECT_PLUGIN_NAME;
	$redirects = codi_redirect_data();
	//save data?
	if(isset($_POST['redirect_urls']) && check_admin_referer($page)) {
		$redirects = codi_redirect_data($_POST['redirect_urls']);
	}
	//generate html
	echo '<div class="wrap">' . "\n";
	echo '<h2>' . __('Redirects') . ' <small>(by <a href="https://github.com/codi-si/wp" target="_blank">codi0</a>)</small></h2>' . "\n";
	echo '<p>E.g. 301 ^/mypage https://site.com/newpage</p>' . "\n";
	echo '<form method="post">' . "\n";
	wp_nonce_field($page);
	echo '<textarea name="redirect_urls" style="width:100%; height:200px;">' . esc_html($redirects) . '</textarea>' . "\n";
	echo '<br><input type="submit" class="button button-primary" value="' . __('Save Changes') . '">' . "\n";
	echo '</form>' . "\n";
	echo '</div>' . "\n";
}

//display plugin settings link
function codi_redirect_admin_link($links) {
	//set vars
	$page = CODI_REDIRECT_PLUGIN_NAME;
	//create link
	$links[] = '<a href="admin.php?page=' . esc_attr($page) . '">' . __('Settings') . '</a>';
	//return
	return $links;
}

//get or set redirect data
function codi_redirect_data($data = null) {
	//get file
	$file = __DIR__ . '/redirects.txt';
	//save data?
	if(is_string($data)) {
		//format data
		$data = trim(strip_tags($data));
		$data = str_replace("\t", " ", $data);
		$data = str_replace("\r\n", "\n", $data);
		$data = preg_replace("/\n+/", "\n", $data);
		//save data
		file_put_contents($file, $data, LOCK_EX);
	} else if(is_file($file)) {
		//read data
		$data = file_get_contents($file);
	}
	//return
	return $data ?: '';
}

//process now
codi_redirect_process();

//init
add_action('admin_menu', 'codi_redirect_admin_menu');