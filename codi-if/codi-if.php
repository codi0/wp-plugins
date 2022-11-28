<?php

/*
Plugin Name: Codi if
Description: Use any WordPress conditional in a post: [if current_user_can="manage_options"]Show this content[/if]
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//if shortcode
function codi_if_shortcode($atts, $content) {
	//set vars
	$results = [];
	$success = false;
	$or = false;
	//loop through attributes
	foreach($atts as $key => $val) {
		//empty value?
		if(is_int($key)) {
			//is and?
			if(strtolower($val) === 'and' || $val === '&&') {
				continue;
			}
			//is or?
			if(strtolower($val) === 'or' || $val === '||') {
				$or = true;
				continue;
			}
			//set key
			$key = $val;
			$val = 'true';
		}
		//set vars
		$prefix = '';
		$matched = false;
		//has prefix?
		if(strpos($key, '.') !== false) {
			//parse prefix
			list($prefix, $key) = array_map('trim', explode('.', $key, 2));
			//has value?
			if(strpos($key, '=') !== false) {
				//parse value
				list($key, $val) = array_map('trim', explode('=', $key, 2));
				//remove quotes
				$val = trim($val, '"');
			}
		}
		//format value
		$val = $val ? array_map('trim', explode(',', $val)) : [ null ];
		//loop through array
		foreach($val as $v) {
			//set vars
			$res = null;
			$expected = substr($v, 0, 4) !== 'not:';
			$v = $expected ? $v : substr($v, 4);
			//is true/false?
			if($v === 'true') $v = true;
			if($v === 'false') $v = false;
			//use prefix?
			if($prefix) {
				//get method
				$method = 'codi_if_' . $prefix;
				//callback exists?
				if(function_exists($method)) {
					$res = $method($key, $v);
				} else {
					$res = codi_if_global($prefix, $key, $v);
				}
			} else {
				//callback exists?
				if(function_exists($key)) {
					$res = $key($v);
				}
			}
			//invalid result?
			if(!is_bool($res)) {
				$matched = false;
				break;
			}
			//expected result?
			if($res === $expected) {
				$matched = true;
				break;
			}
		}
		//match found?
		if($matched) {
			$results[] = true;
		} else {
			$results[] = false;
		}
	}
	//check results
	foreach($results as $res) {
		//or match?
		if($res && $or) {
			$success = true;
			break;
		}
		//and match?
		if(!$res && !$or) {
			$success = false;
			break;
		}
		//next
		$success = $res;
	}
	//return
	return do_shortcode($success ? trim($content) : '');
}

//user data shortcode
function codi_if_user_shortcode($atts, $content='') {
	//set vars
	$content = '';
	$user = wp_get_current_user();
	//valid request?
	if($user && $atts && isset($atts['key']) && $atts['key']) {
		//get key
		$key = $atts['key'];
		//get content
		if(isset($user->$key)) {
			$content = (string) $user->$key;
		} else if(isset($user->{"user_$key"})) {
			$content = (string) $user->{"user_$key"};
		} else {
			$content = (string) get_user_meta($user->ID, $key, true);
		}
		//escape content?
		if(!isset($atts['esc']) || $atts['esc'] !== 'none') {
			$esc = isset($atts['esc']) ? $atts['esc'] : '';
			$method = ($esc === 'attr') ? 'esc_attr' : 'esc_html';
			$content = $method($content);
		}
	}
	//return
	return $content;
}

//post data shortcode
function codi_if_post_shortcode($atts, $content='') {
	//set vars
	$content = '';
	$post = get_post();
	//valid request?
	if($post && $atts && isset($atts['key']) && $atts['key']) {
		//get key
		$key = $atts['key'];
		//get content
		if(isset($post->$key)) {
			$content = (string) $post->$key;
		} else if(isset($post->{"post_$key"})) {
			$content = (string) $post->{"post_$key"};
		} else {
			$content = (string) get_post_meta($post->ID, $key, true);
		}
		//escape content?
		if(!isset($atts['esc']) || $atts['esc'] !== 'none') {
			$esc = isset($atts['esc']) ? $atts['esc'] : '';
			$method = ($esc === 'attr') ? 'esc_attr' : 'esc_html';
			$content = $method($content);
		}
	}
	//return
	return $content;
}

//check if global value
function codi_if_global($global, $key, $val) {
	//format global?
	if(in_array(strtolower($global), [ 'get', 'post', 'cookie', 'request' ])) {
		$global = '_' . strtoupper($global);
	}
	//valid global?
	if(!isset($GLOBALS[$global])) {
		return false;
	}
	//key exists?
	return isset($GLOBALS[$global][$key]) && $GLOBALS[$global][$key] == $val;
}

//check if user value
function codi_if_user($key, $val) {
	//user found?
	if(!$user = wp_get_current_user()) {
		return false;
	}
	//is role?
	if($user && $key === 'role') {
		return in_array($val, $user->roles);
	}
	//key found?
	if(isset($user->$key)) {
		$res = ($user->$key == $val);
	} else if(isset($user->{"user_$key"})) {
		$res = ($user->{"user_$key"} == $val);
	} else {
		$res = get_user_meta($user->ID, $key, true) == $val;
	}
	//return
	return $res;
}

//check if post value
function codi_if_post($key, $val) {
	//post found?
	if(!$post = get_post()) {
		return false;
	}
	//key found?
	if(isset($post->$key)) {
		$res = ($post->$key == $val);
	} else if(isset($user->{"post_$key"})) {
		$res = ($post->{"post_$key"} == $val);
	} else {
		$res = get_post_meta($post->ID, $key, true) == $val;
	}
	//return
	return $res;
}

//init
add_shortcode('if', 'codi_if_shortcode');
add_shortcode('user', 'codi_if_user_shortcode');
add_shortcode('post', 'codi_if_post_shortcode');