<?php

/*
Plugin Name: Codi Google Analytics
Description: Implementation of GA4 tracking, respecting Do Not Track header
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


function codi_ga_data_defaults() {
	return [
		'id' => '',
		'admin' => 0,
		'skip_roles' => '',
	];
}

function codi_ga_data_load() {
	//set vars
	$res = get_option('codi_ga', []);
	$defaults = codi_ga_data_defaults();
	//loop through defaults
	foreach($defaults as $key => $default) {
		if(!isset($res[$key])) {
			$res[$key] = $default;
		}
	}
	//return
	return $res;
}

function codi_ga_data_save(array $data) {
	//set vars
	$res = [];
	$defaults = codi_ga_data_defaults();
	//loop through data
	foreach($defaults as $key => $default) {
		$res[$key] = isset($data[$key]) ? $data[$key] : $default;
	}
	//update data
	update_option('codi_ga', $res);
	//return
	return $res;
}

add_action('init', function() {
	//set vars
	$skip_role = false;
	$data = codi_ga_data_load();
	$roles = $data['skip_roles'] ? array_map('trim', explode(',', $data['skip_roles'])) : [];
	//check for skipped roles
	foreach($roles as $role) {
		if($role && current_user_can($role)) {
			$skip_role = true;
			break;
		}
	}
	//add tracking code?
	if($data['id'] && ($data['admin'] || !is_admin()) && !$skip_role) {
		//format code
		$code = file_get_contents(__DIR__ . '/assets/ga4-dnt.js');
		$code = str_replace('G-XXXXXXXXXX', $data['id'], $code);
		//queue script
		wp_register_script('ga4', false);
		wp_enqueue_script('ga4');
		wp_add_inline_script('ga4', $code);
	}
});

add_action('admin_menu', function() {
	//add setting submenu
	add_options_page('GA Privacy', 'GA Privacy', 'manage_options', 'ga', function() {
		//set vars
		$page = 'codi_ga';
		//save data?
		if(isset($_POST[$page]) && check_admin_referer($page)) {
			codi_ga_data_save($_POST[$page]);
		}
		//load data
		$data = codi_ga_data_load();
		//include template
		include(__DIR__ . '/tpl/settings.tpl');
	});
});