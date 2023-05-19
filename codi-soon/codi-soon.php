<?php

/*
Plugin Name: Codi Soon
Description: Simple coming soon page for users who are not logged in.
Version: 1.0.0
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') or die;


//wait for WP to load
add_action('init', function() {
	//is frontend?
	if(!is_admin() && $GLOBALS['pagenow'] !== 'wp-login.php') {
		//is not logged in?
		if(!is_user_logged_in()) {
			//get file
			$__file = __DIR__ . '/tpl/soon.tpl';
			//file exists?
			if(is_file($__file)) {
				include($__file);
				exit();
			}
		}
	}
});