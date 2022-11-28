<?php

/*
Plugin Name: Codi User
Description: Render and extend user login, registration, password and profile forms using shortcodes.
Version: 1.0.0
Author: Codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_USER_PLUGIN_FILE', __FILE__);
define('CODI_USER_PLUGIN_NAME', basename(__DIR__));

//create iterator
$dir = new RecursiveDirectoryIterator(__DIR__ . '/includes');
$iterator = new RecursiveIteratorIterator($dir);
$matches = new RegexIterator($iterator, '/\.php$/', RecursiveRegexIterator::MATCH);

//loop through matches
foreach($matches as $file) {
	require_once($file);
}