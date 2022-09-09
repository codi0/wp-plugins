<?php

//render post series box
function codi_series_box_render($post_id, array $opts=[]) {
	//part of series?
	if(!$series = get_series($post_id)) {
		return '';
	}
	//get posts
	$posts = get_posts([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'orderby' => 'date',
		'order' => 'asc',
		'fields' => 'ids',
		'no_found_rows' => true,
		'tax_query' => [
			[
				'taxonomy' => 'series',
				'field' => 'id',
				'terms' => $series->term_id,
			]
		],
	]);
	//set vars
	$total = count($posts);
	$position = array_search($post_id, $posts, true) + 1;
	//stop here?
	if($total <= 1 || $position === false) {
		return '';
	}
	//show series box
	$html = '<div class="post-series-box" style="padding:10px; background:#d6efff; border:1px solid #999; margin-bottom:30px;">' . "\n";
	//add name
	$html .= '<div class="name" style="font-weight:bold;">Part ' . $position . ' in the series: <a href="' . get_term_link($series->term_id, 'series') . '" style="color:inherit;">' . esc_html($series->name) . '</a></div>' . "\n";
	//has description?
	if($desc = term_description($series->term_id, 'series')) {
		$html .= '<div class="desc" style="margin-top:2px; font-size:0.85em; font-style:italic;">' . $desc . '</div>' . "\n";
	}
	//add list
	$html .= '<div class="list" style="margin-top:15px; font-size:0.9em;">' . "\n";
	$html .= '<ol>' . "\n";
	//loop through posts
	foreach($posts as $id) {
		//curent post?
		if($id !== $post_id) {
			$item = '<a href="' . get_permalink($id) . '">%s</a>';
		} else {
			$item = '<span class="current">%s</span>';
		}
		//add item
		$html .= '<li style="margin-top:5px; margin-bottom:0;">' . sprintf($item, get_the_title($id))  . '</li>' . "\n";
	}
	$html .= '</ol>' . "\n";
	$html .= '</div>' . "\n";
	$html .= '</div>' . "\n";
	//return
	return $html;
}

//content filter wrapper
function codi_series_box_filter($content) {
	global $post;
	//should filter?
	if(!is_main_query() || !is_singular() || $post->post_type !== 'post') {
		return $content;
	}
	//series box already exists?
	if(stripos($content, 'class="post-series-box"') !== false) {
		return $content;
	}
	//return
	return codi_series_box_render($post->ID) . $content;
}

//shortcode wrapper
function codi_series_box_shortcode($atts, $content='') {
	global $post;
	//set vars
	$opts = is_array($atts) ? $atts : [];
	$post_id = isset($opts['id']) ? (int) $opts['id'] : $post->ID;
	//render series box
	return codi_series_box_render($post_id, $opts);
}

//add hooks
add_filter('the_content', 'codi_series_box_filter');
add_shortcode('codi_series', 'codi_series_box_shortcode');