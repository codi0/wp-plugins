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

function codi_urls_replace_content($old, $new) {
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

function codi_urls_add_redirect($old, $new, $code=302) {
	//load redirects
	$redirects = codi_urls_load_redirects();
	//add redirect
	$redirects[] = [
		'code' => $code,
		'old' => $old,
		'new' => $new,
	];
	//save redirects
	codi_urls_save_redirects($redirects);
}

function codi_urls_load_redirects() {
	//set vars
	$res = [];
	$path = ABSPATH . '/.htaccess';
	//get current domain
	$urlparts = wp_parse_url(get_site_url());
	$domain = $urlparts['host'];
	//file exists?
	if(is_file($path)) {
		//get content
		$content = file_get_contents($path);
		//find redirects?
		if(preg_match('/## BEGIN REDIRECTS: ' . $domain . ' ##(.*)## END REDIRECTS: ' . $domain . ' ##/ms', $content, $match)) {
			//format new lines
			$lines = str_replace("\r\n", "\n", $match[1]);
			//loop through lines
			foreach(explode("\n", $lines) as $line) {
				//format line
				$line = ucfirst(trim($line));
				//skip line?
				if(stripos($line, 'RewriteRule ') !== 0) {
					continue;
				}
				//remove prefix
				if($line = str_replace('RewriteRule ', '', $line)) {
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

function codi_urls_save_redirects(array $redirects) {
	//set vars
	$remove = [];
	$res = false;
	$path = ABSPATH . '/.htaccess';
	//get current domain
	$urlparts = wp_parse_url(get_site_url());
	$domain = $urlparts['host'];
	//can write?
	if(!is_file($path) || is_writable($path)) {
		//format redirects
		foreach($redirects as $key => $val) {
			//valid redirect?
			if(!isset($val['new']) || !$val['new'] || !isset($val['old']) || !$val['old']) {
				unset($redirects[$key]);
				continue;
			}
			//Old: remove host?
			if(strpos($val['old'], '://') !== false) {
				$val['old'] = explode('://', $val['old'])[1];
				$val['old'] = explode('/', $val['old'], 2);
				$val['old'] = ltrim(isset($val['old'][1]) ? $val['old'][1] : $val['old'][0], '/');
				$redirects[$key]['old'] = '/' . $val['old'];
			}
			//Old: optional trailing slash?
			if(strpos($val['old'], '$') === false && strpos($val['old'], '?') === false && $val['old'] !== '/') {
				$redirects[$key]['old'] = rtrim($val['old'], '/') . '/?$';
			}
			//New: remove host?
			if(strpos($val['new'], $domain) !== false) {
				$redirects[$key]['new'] = explode($domain, $val['new'])[1];
			}
		}
		//check for dupes
		foreach($redirects as $key => $val) {
			//second loop
			foreach($redirects as $k => $v) {
				//same key?
				if($k == $key) {
					continue;
				}
				//format vars
				$oldVal = trim(str_replace([ '?$', '$' ], '', $val['old']), '/');
				$newVal = trim(str_replace([ '?$', '$' ], '', $val['new']), '/');
				$oldV = trim(str_replace([ '?$', '$' ], '', $v['old']), '/');
				$newV = trim(str_replace([ '?$', '$' ], '', $v['new']), '/');
				//dupe found?
				if($oldV == $oldVal && $newV == $newVal) {
					$remove[] = min($k, $key);
					continue;
				}
				//infinite redirect found?
				if($oldV == $newVal && $newV == $oldVal) {
					$remove[] = min($k, $key);
					continue;
				}
			}
		}
		//remove dupe keys
		foreach(array_unique($remove) as $k) {
			if(isset($redirects[$k])) {
				unset($redirects[$k]);
			}
		}
		//get content
		$content = is_file($path) ? file_get_contents($path) : '';
		//remove old redirects
		$content = trim(preg_replace('/## BEGIN REDIRECTS: ' . $domain . ' ##(.*)## END REDIRECTS: ' . $domain . ' ##/ms', '', $content));
		//add new redirects?
		if(count($redirects) > 0) {
			$str = '## BEGIN REDIRECTS: ' . $domain . ' ##' . "\n";
			$str .= '<IfModule mod_rewrite.c>' . "\n";
			$str .= '	RewriteEngine On' . "\n";
			foreach($redirects as $key => $val) {
				$str .= '	RewriteCond %{HTTP_HOST} ^' . $domain . '$' . "\n";
				$str .= '	RewriteRule ^' . ltrim($val['old'], '/') . ' ' . $val["new"] . ' [L,R=' . $val["code"] . ']' . "\n";
			}
			$str .= '</IfModule>' . "\n";	
			$str .= '## END REDIRECTS: ' . $domain . ' ##';
			//prepend content
			$content = $str . "\n\n" . $content;
		}
		//update file
		$res = @file_put_contents($path, $content, LOCK_EX) !== false;
	}
	//return
	return $res ? 1 : 0;
}

add_action('pre_post_update', function($id) {
	codi_urls_cache('id', $id);
	codi_urls_cache('permalink', get_permalink($id));
});

add_action('post_updated', function($id, $post, $old_post) {
	//get cached post ID
	$id = codi_urls_cache('id');
	//valid post update?
	if(!$id || !$old_post) {
		return;
	}
	//not already published?
	if($post->post_status != 'publish' || $old_post->post_status != 'publish') {
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
	codi_urls_replace_content($old, $new);
	//add redirect
	codi_urls_add_redirect($old, $new);
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
				$data['res'] = codi_urls_save_redirects($data['redirects']);
			} else {
				$data['res'] = codi_urls_replace_content($data['old'], $data['new']);
			}
		}
		//load redirects
		$data['redirects'] = codi_urls_load_redirects();
		//include template
		include(__DIR__ . '/tpl/settings.tpl');
	});
});