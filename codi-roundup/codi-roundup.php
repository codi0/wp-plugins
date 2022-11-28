<?php

/*
Plugin Name: Codi Roundup Posts
Description: Create Roundup posts from curated RSS feeds
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_ROUNDUP_PLUGIN_FILE', __FILE__);
define('CODI_ROUNDUP_PLUGIN_NAME', basename(__DIR__));

//create iterator
$dir = new RecursiveDirectoryIterator(__DIR__ . '/includes');
$iterator = new RecursiveIteratorIterator($dir);
$matches = new RegexIterator($iterator, '/\.php$/', RecursiveRegexIterator::MATCH);

//loop through matches
foreach($matches as $file) {
	require_once($file);
}