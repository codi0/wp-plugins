<?php

/*
Plugin Name: Codi Debug
Description: Display debug bar that includes load time, memory usage and number of queries
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//define constants
define('CODI_DEBUG_TIME', microtime(true));
define('CODI_DEBUG_MEM', memory_get_usage());

//generate debug bar
function codi_debug_bar() {
	//set vars
	$dbs = [];
	//debug data
	$data = [
		'time' => number_format(microtime(true) - CODI_DEBUG_TIME, 5) . 's',
		'mem' => number_format((memory_get_usage() - CODI_DEBUG_MEM) / 1024, 0) . 'kb',
		'mem_peak' => number_format(memory_get_peak_usage() / 1024, 0) . 'kb',
		'queries' => 0,
		'queries_log' => [],
	];
	//check prototypr?
	if(function_exists('prototypr')) {
		//get kernel
		$names = [];
		$kernel = prototypr();
		//get kernel
		foreach($kernel->config('db_services') as $key) {
			if($db = $kernel->service($key)) {
				//get db name
				$dbname = isset($db->dbname) ? $db->dbname : $db->name;
				//already used?
				if(!in_array($dbname, $names)) {
					$dbs[] = $db;
					$names[] = $dbname;
				}
			}
		}
	}
	//add default?
	if(empty($dbs)) {
		$dbs[] = $GLOBALS['wpdb'];
	}
	//loop through db objects
	foreach($dbs as $db) {
		//get db name
		$dbname = isset($db->dbname) ? $db->dbname : $db->name;
		//count queries
		$data['queries'] += count($db->queries);
		//get query log
		$data['queries_log'][$dbname] = array_map(function($item) {
			//set vars
			$result = [];
			//is array?
			if(is_array($item)) {
				$result['query'] = $item[0];
				$result['time'] = isset($item[1]) ? number_format($item[1], 5) : 0;
			} else {
				$result['query'] = $item;
				$result['time'] = 0;
			}
			//return
			return $result;
		}, $db->queries);
	}
	//generate html
	$html  = '<div id="debug-bar" style="font-size:13px; padding:10px; background:#dfdfdf;">';
	$html .= '<div class="heading" onclick="return this.nextSibling.style.display=\'block\';">';
	$html .= '<span style="font-weight:bold;">Debug bar:</span> &nbsp;' . $data['time'] . ' &nbsp;|&nbsp; ' . $data['mem'] . ' &nbsp;|&nbsp; ';
	$html .= '<span style="color:blue;cursor:pointer;">' . $data['queries'] . ' queries &raquo;</span>';
	$html .= '</div>';
	if($data['queries_log']) {
		$html .= '<div class="queries" style="display:none;">';
		foreach($data['queries_log'] as $name => $queries) {
			$html .= '<p>DB name: ' . $name . '</p>';
			$html .= '<ol style="padding-left:20px; margin-left:0;">';
			foreach($queries as $q) {
				if($q['time'] > 0.1) {
					$q['time'] = '<span style="color:red;">' . $q['time'] . '</span>';
				}
				$html .= '<li style="margin:8px 0 0 0; line-height:1.1;">' . $q['query'] . ' | ' . $q['time'] . '</li>';
			}
			$html .= '</ol>';
		}
		$html .= '</div>';
	} else {
		$html .= '<div class="no-queries" style="display:none;">Query log empty</div>';
	}
	$html .= '</div>';
	//hide admin text?
	if(is_admin()) {
		$html .= '<style>#wpfooter { display:none; }</style>';
		$html .= '<style>@media(min-width:780px) { #debug-bar { margin-left:35px; } }</style>';
		$html .= '<style>@media(min-width:960px) { #debug-bar { margin-left:160px; } }</style>';
	}
	//return
	echo $html;
}

//display debug bar
add_action('wp_footer', 'codi_debug_bar', 999);
add_action('admin_footer', 'codi_debug_bar', 999);