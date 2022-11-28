<?php

/*
Plugin Name: Codi Dom
Description: Manipulate the output of any WordPress frontend or admin page using php DOMDocument.
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_DOM_PLUGIN_FILE', __FILE__);
define('CODI_DOM_PLUGIN_NAME', basename(__DIR__));
define('CODI_DOM_CLASS', 'Prototypr\Dom');

//start helper
function codi_dom_start() {
	//is cron?
	if(wp_doing_cron()) {
		return;
	}
	//set page type
	if(isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
		$type = 'login';
	} elseif(is_admin() || is_network_admin()) {
		$type = 'admin';
	} else {
		$type = 'frontend';
	}
	//load DOM class?
	if(!class_exists(CODI_DOM_CLASS, false)) {
		require_once(__DIR__ . '/vendor/' . str_replace('\\', '/', CODI_DOM_CLASS) . '.php');
	}
	//buffer
	ob_start();
	//set constants
	define('CODI_DOM_LEVEL', ob_get_level());
	define('CODI_DOM_TYPE', $type);
}

//stop helper
function codi_dom_stop() {
	//is buffered?
	if(!defined('CODI_DOM_LEVEL')) {
		return;
	}
	//set vars
	$action = 'codi_dom';
	$type = CODI_DOM_TYPE;
	$class = CODI_DOM_CLASS;
	//flush extra levels
	while(ob_get_level() > CODI_DOM_LEVEL) {
		ob_end_flush();
	}
	//get output
	$html = ob_get_clean();
	//is html?
	if(strpos($html, '<html') !== false) {
		//anything to process?
		if(has_action($action) || has_action($action . '_' . $type)) {
			//create object
			$dom = new $class;
			//load page html
			$dom->load($html);
			//execute dom actions
			do_action($action, $dom);
			do_action($action . '_' . $type, $dom);
			//get output
			$html = $dom->save();
		}
	}
	//filter html
	$html = apply_filters($action . '_html', $html);
	$html = apply_filters($action . '_' . $type . '_html', $html);
	//output?
	if(strlen($html) > 0) {
		echo $html;
	}
}

//init
add_action('init', 'codi_dom_start', 999999);
add_action('shutdown', 'codi_dom_stop', -999999);