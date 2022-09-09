<?php

/*
Plugin Name: Codi Post Series
Description: Group and link posts together in series.
Version: 1.0.0
Author: Codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//define constants
define('CODI_SERIES_PLUGIN_FILE', __FILE__);
define('CODI_SERIES_PLUGIN_NAME', basename(__DIR__));

//global function
if(!function_exists('get_series')) {
	function get_series($post_id = null) {
		global $post;
		//get post ID
		$post_id = (int) $post_id ?: ($post ? $post->ID : 0);
		//extract series?
		if($series = wp_get_post_terms($post_id, 'series')) {
			$series = current($series);
		}
		//return
		return $series;
	}
}

//create iterator
$dir = new RecursiveDirectoryIterator(__DIR__ . '/includes');
$iterator = new RecursiveIteratorIterator($dir);
$matches = new RegexIterator($iterator, '/\.php$/', RecursiveRegexIterator::MATCH);

//loop through matches
foreach($matches as $file) {
	require_once($file);
}