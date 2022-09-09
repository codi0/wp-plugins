<?php

/*
Plugin Name: Codi php
Description: Execute php code in WordPress page
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//shortcode handler
function codi_php_shortcode($atts, $content='') {
	try {
		$content = trim($content);
		if($content) {
			$last = substr($content, -1);
			if($last !== ';') {
				$content .= ';';
			}
			ob_start();
			eval($content);
			return ob_get_clean();
		}
	} catch (Exception $e) {
		//do nothing
	}
}

//save post handler
function codi_php_save_hook($post_ID, $post, $update) {
	//allowed to save php code?
	if(!current_user_can('edit_others_posts')) {
		//filter content
		$content = preg_replace('/\[php(.*?)\](.*?)\[\/php\] ?/is', '', $post->post_content);
		//update post?
		if($content !== $post->post_content) {
			//set content
			$post->post_content = $content;
			//unhook action
			remove_action('save_post', 'codi_php_save_hook');
			//update post
			wp_update_post($post);
			//re-hook action
			add_action('save_post', 'codi_php_save_hook');
		}
	}
}

//init
add_shortcode('php', 'codi_php_shortcode');
add_action('save_post', 'codi_php_save_hook', 10, 3);