<?php

//roundup shortcode
function codi_roundup_shortcode($atts, $content='') {
	//set opts
	$opts = array_merge([
		'id' => 0,
		'url' => '',
		'limit' => 0,
		'date' => 'true',
		'domain' => 'true',
	], $atts ?: []);
	//set vars
	$html = '';
	$items = [];
	$count = 0;
	//parse urls
	$opts['url'] = array_map('trim', explode(';', $opts['url']));
	//use ID?
	if($opts['id'] > 0) {
		//get stories
		$items = codi_roundup_get_stories([
			'roundup' => $opts['id'],
		], ARRAY_A);
	} else {
		//loop through urls
		foreach($opts['url'] as $url) {
			//has items?
			if($tmp = codi_roundup_get_feed($url)) {
				//loop through items
				foreach($tmp as $k => $v) {
					//add item?
					if(!isset($items[$k])) {
						$items[$k] = $v;
					}
				}
			}
		}
	}
	//order by date
	uasort($items, function($a, $b) {
		return $a['time'] > $b['time'] ? -1 : 1;
	});
	//open container
	$html .= '<div class="roundup-list">';
	//loop through items
	foreach($items as $item) {
		//parse domain
		$domain = str_replace('www.', '', parse_url($item['link'], PHP_URL_HOST));
		//display
		$html .= '<div class="item">';
		$html .= '<div class="link"><a href="' . $item['link'] . '" target="_blank">' . $item['title'] . '</a></div>';
		$html .= '<div class="snippet">' . $item['snippet'] . '</div>';
		$html .= '<div class="about">';
		if($opts['date'] !== 'false') {
			$html .= '<span class="date">' . date('l, d F', $item['time']) . '</span>';
			if($opts['domain'] !== 'false') {
				$html .= ' &middot; ';
			}
		}
		if($opts['domain'] !== 'false') {
			$html .= '<span class="domain">' . $domain . '</span>';
		}
		$html .= '</div>';
		$html .= '</div>';
		//add one
		$count++;
		//stop here?
		if($opts['limit'] && $opts['limit'] <= $count) {
			break;
		}
	}
	//close container
	$html .= '</div>';
	//return
	return $html;
}

//frontend assets
function codi_roundup_frontend_assets() {
	global $post;
	//load planner assets?
	if(is_singular() && has_shortcode($post->post_content, 'codi_roundup')) {
		//get plugin url
		$url = plugin_dir_url(CODI_ROUNDUP_PLUGIN_FILE) . 'assets';
		$dir = plugin_dir_path(CODI_ROUNDUP_PLUGIN_FILE) . 'assets';
		//register assets
		wp_enqueue_style('roundup', $url . '/shortcode.css', [], filemtime($dir . '/shortcode.css'));
	}
}

//add hooks
add_action('wp_enqueue_scripts', 'codi_roundup_frontend_assets');

//add shortcodes
add_shortcode('codi_roundup', 'codi_roundup_shortcode');