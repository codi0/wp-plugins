<?php

/*
Plugin Name: Codi Content Planner
Description: Collaboratively suggest, discuss and plan content ideas.
Version: 1.0.0
Author: Codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_PLANNER_PLUGIN_FILE', __FILE__);
define('CODI_PLANNER_PLUGIN_NAME', basename(__DIR__));

//config vars
define('CODI_PLANNER_POST_TYPE', 'planner');
define('CODI_PLANNER_POST_TAXONOMY', 'series');

//create iterator
$dir = new RecursiveDirectoryIterator(__DIR__ . '/includes');
$iterator = new RecursiveIteratorIterator($dir);
$matches = new RegexIterator($iterator, '/\.php$/', RecursiveRegexIterator::MATCH);

//loop through matches
foreach($matches as $file) {
	require_once($file);
}