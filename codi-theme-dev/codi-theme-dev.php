<?php

/*
Plugin Name: Codi Theme Dev
Description: Switch theme using ?tpl=X
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//define constants
define('CODI_TD_PLUGIN_FILE', __FILE__);
define('CODI_TD_PLUGIN_NAME', basename(__DIR__));

//config vars
define('CODI_TD_GET_VAR', 'tpl');
define('CODI_TD_COOKIE_VAR', 'td-theme');

//update cookie
add_action('plugins_loaded', function() {
	//theme param found?
	if(isset($_GET[CODI_TD_GET_VAR]) && $_GET[CODI_TD_GET_VAR]) {
		//set local value
		$_COOKIE[CODI_TD_COOKIE_VAR] = $_GET[CODI_TD_GET_VAR];
		//set cookie header
		setcookie(CODI_TD_COOKIE_VAR, $_GET[CODI_TD_GET_VAR], 0, '/');
	}
});

//filter template
add_filter('template', function($template) {
	//get cookie value
	$theme = isset($_COOKIE[CODI_TD_COOKIE_VAR]) ? $_COOKIE[CODI_TD_COOKIE_VAR] : '';
	//return
	return $theme ?: $template;
});

//filter theme
add_filter('stylesheet', function($stylesheet) {
	//get cookie value
	$theme = isset($_COOKIE[CODI_TD_COOKIE_VAR]) ? $_COOKIE[CODI_TD_COOKIE_VAR] : '';
	//return
	return $theme ?: $stylesheet;
});