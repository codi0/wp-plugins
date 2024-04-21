<?php

/*
Plugin Name: Codi No Cookies
Description: Remove core WordPress cookies when not logged in
Version: 1.0.0
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') or die;


//define constants
define('CODI_NOCOOKIES_PLUGIN_FILE', __FILE__);
define('CODI_NOCOOKIES_PLUGIN_NAME', basename(__DIR__));

//cookies check
function codi_nocookies_check() {
	//is logged in?
	if(is_user_logged_in()) {
		return;
	}
	//loop through cookies
	foreach(array_keys($_COOKIE) as $k) {
		//delete cookie?
		if(preg_match('/^(wordpress|wp)/i', $k)) {
			setcookie($k, false, time() - YEAR_IN_SECONDS, '', COOKIE_DOMAIN);
		}
	}
}

//add hooks
add_action('wp', 'codi_nocookies_check', 9999);
add_action('wp_logout', 'codi_nocookies_check', 9999);