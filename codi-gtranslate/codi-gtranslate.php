<?php

/*
Plugin Name: Codi Google Translate
Description: Lazy load implementation of google translate (just add a div with id="gTranslate" to your theme)
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


add_action('wp_enqueue_scripts', function() {
	//format code
	$code = file_get_contents(__DIR__ . '/assets/gtranslate.js');
	//queue script
	wp_register_script('gtranslate', false);
	wp_enqueue_script('gtranslate');
	wp_add_inline_script('gtranslate', $code);
});