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
			//has cookie?
			if(!isset($_COOKIE['soon']) || $_COOKIE['soon'] !== 'preview') {
				//has preview?
				if(isset($_GET['preview'])) {
					//set cookie
					setcookie('soon', 'preview', 0, '/');
				} else {
					//get blog ID
					$blog_id = get_current_blog_id();
					//list files
					$__files = [];
					//add blog ID
					$__files[] = WP_CONTENT_DIR . '/soon-' . $blog_id . '.tpl';
					//add wp-content regular?
					if(!is_multisite()) {
						$__files[] = WP_CONTENT_DIR . '/soon.tpl';
					}
					//add fallback
					$__files[] = __DIR__ . '/tpl/soon.tpl';
					//loop through files
					foreach($__files as $__file) {
						//file exists?
						if(is_file($__file)) {
							include($__file);
							exit();
						}
					}
				}
			}
		}
	}
}, -99);