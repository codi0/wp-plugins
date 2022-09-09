<?php

//url config
function codi_user_url($name, $from = '') {
	//set vars
	$config = get_option('codi_user_urls') ?: [];
	//stop here?
	if(!isset($config[$name]) || !$config[$name]) {
		return '';
	}
	//set url
	$url = get_page_link($config[$name]);
	//replace from?
	if($url && $from) {
		$url = trim(str_replace([ $from . '&amp;', $from . '&', $from ], $url . '?', $from), '?');
	}
	//return
	return $url;
}

//login url filter
function codi_user_url_login($login_url, $redirect, $force_reauth) {
	//set vars
	$from = home_url('wp-login.php');
	$url = codi_user_url('login', $from) ?: $login_url;
	//add redirect?
	if($redirect) {
		$url = add_query_arg('redirect_to', urlencode($redirect), $url);
	}
	//add force?
    if($force_reauth) {
        $url = add_query_arg('reauth', '1', $url);
    }
    //return
    return $url;
}

//logout url filter
function codi_user_url_logout($logout_url, $redirect) {
	//set vars
	$from = home_url('wp-login.php?action=logout');
	$url = codi_user_url('logout', $from) ?: $logout_url;
	//add redirect?
	if($redirect) {
		$url = add_query_arg('redirect_to', urlencode($redirect), $url);
	}
	//return
	return $url;
}

//password url filter
function codi_user_url_password($lostpassword_url, $redirect) {
	//set vars
	$from = home_url('wp-login.php?action=lostpassword');
	$url = codi_user_url('password', $from) ?: $lostpassword_url;
	//add redirect?
	if($redirect) {
		$url = add_query_arg('redirect_to', urlencode($redirect), $url);
	}
	//return
	return $url;
}

//register url filter
function codi_user_url_register($register_url) {
	//set vars
	$from = home_url('wp-login.php?action=register');
	$url = codi_user_url('register', $from) ?: $register_url;
	//return
	return $url;
}

//add filters
add_filter('login_url', 'codi_user_url_login', 10, 3);
add_filter('logout_url', 'codi_user_url_logout', 10, 2);
add_filter('lostpassword_url', 'codi_user_url_password', 10, 2);
add_filter('register_url', 'codi_user_url_register');