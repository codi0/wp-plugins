<?php

/*
Plugin Name: Codi JS Defer
Description: Automatically defer all javascript, including include js.
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//start defer
function codi_defer_start() {
	//buffer
	ob_start();
	//mark as buffered
	define('CODI_DEFER_STARTED', true);
}

//start defer
function codi_defer_stop() {
	//can process?
	if(defined('CODI_DEFER_STARTED')) {
		//get content
		$content = ob_get_clean();
		//filter script tags
		$content = preg_replace_callback('/<script([^>]*)>(.*)<\/script>/isU', function($matches) {
			//set vars
			$inline = false;
			$script = str_replace(' type="text/javascript"', '', $matches[0]);
			//is inline?
			if(stripos($matches[1], ' src=') !== false) {
				//add defer?
				if(stripos($matches[1], ' defer') === false) {
					$script = str_replace('<script', '<script defer', $script);
				}
			} else {
				//flag as inline
				$inline = true;
				//add type?
				if(stripos($matches[1], ' type="module"') === false) {
					$script = str_replace('<script', '<script type="module"', $script);
				}
			}
			//return script
			return apply_filters('defer_script', $script, $inline, $matches);
		}, $content);
		//echo result
		echo $content;
	}
}

//tinymce plugin update
function codi_defer_plugin_tinymce($script, $inline, $matches) {
	//is inline?
	if($inline) {
		$script = str_replace('tinyMCEPreInit', 'window.tinyMCEPreInit', $script);
	}
	//return
	return $script;
}


//add hooks
add_action('wp_head', 'codi_defer_start', -9999);
add_action('wp_footer', 'codi_defer_stop', 9999);
add_filter('defer_script', 'codi_defer_plugin_tinymce', 10, 3);