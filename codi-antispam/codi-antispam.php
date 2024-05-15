<?php

/*
Plugin Name: Codi Anti-Spam
Description: Automatically protects all front-end forms with a Cloudflare Turnstile challenge.
Version: 1.0.0
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') or die;


//define constants
define('CODI_ASPAM_PLUGIN_FILE', __FILE__);
define('CODI_ASPAM_PLUGIN_NAME', basename(__DIR__));
define('CODI_ASPAM_KEY', 'codi_aspam');


function codi_aspam_log($name, array $data=[]) {
	//encode data
	$data = $data ? json_encode($data, JSON_PRETTY_PRINT) : '';
	//log to file
	@file_put_contents(__DIR__ . '/logs/' . $name . '.log', trim(date('Y-m-d H:i:s') . ' ' . $data) . "\n", FILE_APPEND|LOCK_EX);
}

function codi_aspam_data() {
	//set vars
	$data = [];
	$key = null;
	$isMulti = false;
	$args = func_get_args();
	$defaults = [
		'site_key' => '',
		'secret_key' => '',
		'log_interactive' => 1,
		'log_failures' => 1,
		'log_timeouts' => 1,
	];
	//format args?
	if(count($args) > 0) {
		if(is_array($args[0])) {
			$data = $args[0];	
			$isMulti = true;
		} else if(is_string($args[0])) {
			if(count($args) > 1) {
				$data = [ $args[0] => $args[1] ];
			} else {
				$key = $args[0];
			}
		}
	}
	//set empty?
	if($data && $isMulti) {
		foreach($defaults as $k => $v) {
			if($v == 1 && !isset($data[$k])) {
				$data[$k] = 0;
			}
		}
	}
	//load data
	$res = get_option(CODI_ASPAM_KEY, []);
	$res = array_merge($defaults, $res);
	//set data?
	if($data) {
		$res = array_merge($res, $data);
		update_option(CODI_ASPAM_KEY, $res);
	}
	//use key?
	if($key) {
		$res = isset($res[$key]) ? $res[$key] : null;
	}
	//return
	return $res;
}

function codi_aspam_token($field, array $data=null) {
	//use $_POST array?
	if($data === null) {
		$data = $_POST;
	}
	//loop through data
	foreach($data as $k => $v) {
		//match found?
		if($k === $field) {
			return $v;
		}
		//parse serialized string?
		if($v && is_string($v) && strpos($v, '=') > 0) {
			parse_str($v, $arr);
			if($arr && is_array($arr)) {
				$v = $arr;
			}
		}
		//recursive check?
		if($v && is_array($v)) {
			if($t = codi_aspam_token($field, $v)) {
				return $t;
			}
		}
	}
	//none
	return '';
}

function codi_aspam_init() {
	//set vars
	$field = '_astoken';
	$data = codi_aspam_data();
	$isAdmin = is_admin();
	$isAjax = wp_doing_ajax();
	$isDebug = defined('WP_DEBUG') && WP_DEBUG;
	$isLoggedIn = !$isDebug && is_user_logged_in();
	$checkToken = ($_SERVER['REQUEST_METHOD'] === 'POST') && !$isLoggedIn && ($isAjax || !$isAdmin);
	$loadChallenge = apply_filters('codi_aspam_load_challenge', !$isAdmin && !$isAjax && !$isLoggedIn);
	$action = ($isAjax && isset($_REQUEST['action'])) ? $_REQUEST['action'] : '';
	//pre-checks?
	if($checkToken) {
		//get post data
		$post = $_POST;
		//remove ajax action?
		if($isAjax && isset($post['action'])) {
			unset($post['action']);
		}
		//is empty?
		if(empty($post)) {
			$checkToken = false;
		}
		//filter tests
		$tests = apply_filters('codi_aspam_check_tests', [
			'ajaxNonce' => function($action) {
				return $action && stripos($action, 'nonce') !== false;
			},
			'ajaxNotForm' => function($action) {
				return $action && !preg_match('/(^|\-|\_)form($|\-|\_)/i', $action);
			}
		]);
		//loop through tests
		foreach($tests as $test) {
			//should check token?
			if(!$checkToken || $test($action)) {
				$checkToken = false;
				break;
			}
		}
	}
	//check for token?
	if($checkToken && $data['site_key'] && $data['secret_key']) {
		//set vars
		$json = [];
		$error = true;
		$tokenValue = codi_aspam_token($field);
		//has token?
		if($tokenValue) {
			//set url
			$url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
			//format request data
			$stream = stream_context_create([
				'http' => [
					'method' => 'POST',
					'timeout' => 3,
					'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
					'content' => http_build_query([
						'secret' => $data['secret_key'],
						'response' => $tokenValue,
					]),
				],
			]);
			//make api call
			if($response = file_get_contents($url, false, $stream)) {
				//json decode
				$json = json_decode($response, true);
				//success?
				if($json && isset($json['success']) && $json['success']) {
					$error = false;
				}
			} else {
				//timeout
				$error = false;
				//log timeout?
				if(codi_aspam_data('log_timeouts')) {
					codi_aspam_log('timeouts');
				}
			}
		}
		//failed?
		if($error) {
			//log failure?
			if(codi_aspam_data('log_failures')) {
				codi_aspam_log('failures', [
					'uri' => $isAjax ? wp_get_referer() : $_SERVER['REQUEST_URI'],
					'action' => $action,
					'ip' => $_SERVER['REMOTE_ADDR'],
					'token' => $tokenValue,
					'response' => $json,
				]);
			}
			//display message
			echo "<p>It looks like you've been flagged by our anti-spam system. If this is a mistake, please <a href=\"javascript:history.back();\">try again</a>.</p>";
			exit();
		}
	}
	//load challenge?
	if($loadChallenge && $data['site_key'] && $data['secret_key']) {
		//set vars
		$vars = array_map('esc_js', [
			$data['site_key'],
			$field,
			admin_url('admin-ajax.php'),
			$isDebug
		]);
		//format inine code
		$inline = file_get_contents(__DIR__ . '/assets/turnstile.js');
		$inline = str_replace([ 'TS_SITE_KEY', 'TS_FIELD', 'TS_AJAX', 'TS_DEBUG' ], $vars, $inline);
		//queue scripts
		wp_register_script('turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=tsInit', [], null);
		wp_script_add_data('turnstile', 'strategy', 'defer');
		wp_enqueue_script('turnstile');
		wp_add_inline_script('turnstile', $inline, 'before');
	}
}

function codi_aspam_admin() {
	//add setting submenu
	add_options_page('Cloudflare TurnStile', 'Cloudflare TurnStile', 'manage_options', CODI_ASPAM_KEY, function() {
		//set vars
		$page = CODI_ASPAM_KEY;
		$log = (isset($_GET['log']) && $_GET['log']) ? esc_html($_GET['log']) : '';
		$tpl = $log ? 'log' : 'settings';
		//save data?
		if(isset($_POST[$page]) && check_admin_referer($page)) {
			codi_aspam_data($_POST[$page]);
		}
		//load data
		$data = array_map('esc_html', codi_aspam_data());
		//load log files
		$logFiles = glob(__DIR__ . '/logs/*.log');
		//include template
		include(__DIR__ . '/tpl/' . $tpl . '.tpl');
	});
}

function codi_aspam_ajax() {
	//log interactive?
	if(codi_aspam_data('log_interactive')) {
		codi_aspam_log('interactive', [
			'uri' => wp_get_referer(),
			'ip' => $_SERVER['REMOTE_ADDR'],
		]);
		echo '1';
	} else {
		echo '0';
	}
	//exit
	wp_die();
}

//add actions
add_action('init', 'codi_aspam_init');
add_action('admin_menu', 'codi_aspam_admin');
add_action('wp_ajax_aspam_log', 'codi_aspam_ajax');
add_action('wp_ajax_nopriv_aspam_log', 'codi_aspam_ajax');