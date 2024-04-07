<?php

/*
Plugin Name: Codi URL Manager
Description: Auto-update links when post slug updated
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


function codi_urls_cache($key, $val=null) {
	static $cache = [
		'id' => '',
		'permalink' => '',
	];
	if($val) {
		$cache[$key] = $val;
	}
	return isset($cache[$key]) ? $cache[$key] : null;
}

function codi_urls_replace($old, $new) {
	global $wpdb;
	//set vars
	$rows = 0;
	$old = strip_tags($old);
	$new = strip_tags($new);
	//run query?
	if($old && $new) {
		//create sql
		$sql = $wpdb->prepare("UPDATE {$wpdb->prefix}posts
				SET post_content = REPLACE(post_content, %s, %s)
				WHERE post_content LIKE %s", [ $old, $new, '%'.$wpdb->esc_like($old).'%' ]);
		//run query
		$rows = $wpdb->query($sql) ?: 0;
	}
	//return
	return $rows;
}

function codi_urls_htaccess_load() {
	//set vars
	$res = [];
	$path = ABSPATH . '/.htaccess';
	//file exists?
	if(is_file($path)) {
		//get content
		$content = file_get_contents($path);
		//find redirects?
		if(preg_match('/## BEGIN REDIRECTS ##(.*)## END REDIRECTS ##/ms', $content, $match)) {
			//format new lines
			$lines = str_replace("\r\n", "\n", $match[1]);
			//loop through lines
			foreach(explode("\n", $lines) as $line) {
				//valid line?
				if($line = trim(str_replace('RewriteRule', '', ucfirst($line)))) {
					//split into parts
					$parts = preg_split('/\s+/', $line);
					//add to array?
					if(count($parts) == 3) {
						$res[] = [
							'code' => (int) str_replace([ '[L,R=', ']' ], '', $parts[2]),
							'old' => (string) str_replace([ '^/', '^' ], '/', $parts[0]),
							'new' => (string) $parts[1],
						];
					}
				}
			}
		}
	}
	//return
	return $res;
}

function codi_urls_htaccess_save(array $redirects) {
	//set vars
	$res = false;
	$redirectsNum = 0;
	$path = ABSPATH . '/.htaccess';
	$domain = '';
	//can write?
	if(!is_file($path) || is_writable($path)) {
		//format redirects
		foreach($redirects as $key => $val) {
			if(!isset($val['new']) || !$val['new']) {
				unset($redirects[$key]);
			} else {
				$redirectsNum++;
				//format old url?
				if(strpos($val['old'], '://') !== false) {
					$val['old'] = explode('://', $val['old'])[1];
					$val['old'] = explode('/', $val['old'], 2);
					$val['old'] = ltrim(isset($val['old'][1]) ? $val['old'][1] : $val['old'][0], '/');
					$redirects[$key]['old'] = '/' . $val['old'];
				}
			}
		}
		//get content
		$content = is_file($path) ? file_get_contents($path) : '';
		//remove old redirects
		$content = trim(preg_replace('/## BEGIN REDIRECTS ##(.*)## END REDIRECTS ##/ms', '', $content));
		//add redirects?
		if($redirectsNum > 0) {
			$str = '## BEGIN REDIRECTS ##' . "\n";
			$str .= '<IfModule mod_rewrite.c>' . "\n";
			$str .= '	RewriteEngine On' . "\n";
			foreach($redirects as $key => $val) {
				$str .= '	RewriteRule ^' . ltrim($val['old'], '/') . ' ' . $val["new"] . ' [L,R=' . $val["code"] . ']' . "\n";
			}
			$str .= '</IfModule>' . "\n";	
			$str .= '## END REDIRECTS ##';
			//prepend content
			$content = $str . "\n\n" . $content;
			//update file
			$res = @file_put_contents($path, $content, LOCK_EX) !== false;
		} else {
			$res = true;
		}
	}
	//return
	return $res ? 1 : 0;
}

add_action('pre_post_update', function($id) {
	codi_urls_cache('id', $id);
	codi_urls_cache('permalink', get_permalink($id));
});

add_action('post_updated', function($id, $post, $old_post) {
	//valid post update?
	if($id != codi_urls_cache('id') || empty($old_post)) {
		return;
	}
	//get permalinks
	$old = codi_urls_cache('permalink');
	$new = get_permalink($id);
	//has changed?
	if($old == $new) {
		return;
	}
	//replace link
	codi_urls_replace($old, $new);
}, 10, 3);

add_action('admin_menu', function() {
	//add setting submenu
	add_options_page('URL manager', 'URL manager', 'manage_options', 'codi-urls', function() {
		//set page
		$page = 'codi_urls';
		$data = [ 'res' => 0, 'old' => '', 'new' => '', 'redirects' => '' ];
		//valid $_POST?
		if(isset($_POST[$page]) && check_admin_referer($page)) {
			//get data
			$data = array_merge($data, $_POST[$page]);
			//update redirects?
			if($_POST['action'] == 'redirect') {
				$data['res'] = codi_urls_htaccess_save($data['redirects']);
			} else {
				$data['res'] = codi_urls_replace($data['old'], $data['new']);
			}
		}
		//load redirects
		$data['redirects'] = codi_urls_htaccess_load();
		//include template
		include(__DIR__ . '/tpl/settings.tpl');
	});
});