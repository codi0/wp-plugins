<?php

/*
Plugin Name: Codi Theme Debloat
Description: Improve WP theme loading performance
Version: 1.0.0
Author: codi0
Author URI: https://codi.io
*/

defined('ABSPATH') or die;


add_action('wp', function() {

	//disable emoji support
	add_filter('emoji_svg_url', '__return_false');
	remove_action('wp_print_styles', 'print_emoji_styles');
	remove_action('wp_head', 'print_emoji_detection_script', 7);

	//disable oembed
	remove_action('rest_api_init', 'wp_oembed_register_route');
	remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
	remove_action('wp_head', 'wp_oembed_add_discovery_links');
	remove_action('wp_head', 'wp_oembed_add_host_js');

	//remove rest meta
	remove_action('wp_head', 'rest_output_link_wp_head');  
	remove_action('template_redirect', 'rest_output_link_header', 11);

	//remove shortlink meta
	remove_action('wp_head', 'wp_shortlink_wp_head');
	remove_action('template_redirect', 'wp_shortlink_header', 11);

	//remove feed meta
	remove_action('wp_head', 'feed_links_extra', 3);
	remove_action('wp_head', 'feed_links', 2);
	remove_action('wp_head', 'wc_products_rss_feed');

	//remove other meta
	remove_action('wp_head', 'wp_generator');
	remove_action('wp_head', 'rsd_link');
	remove_action('wp_head', 'wlwmanifest_link');
	add_filter('googlesitekit_generator', function($meta) { return ''; });

}, 99);

add_filter('codi_dom_frontend_html', function($html) {
	global $post;
	//set vars
	$styles = [];
	$scripts = [];
	//scan styles
	$html = preg_replace_callback('/<link([^\>]*)>|<style[^\>]*>(.*)<\/style>/imsU', function($match) use(&$styles) {
		//can modify?
		if(stripos($match[0], '<style') === 0) {
			//add to styles
			$styles[] = $match[0];
			//remove style
			$match[0] = '';
		} else if(stripos($match[0], '<link') === 0 && stripos($match[1], 'stylesheet') !== false && stripos($match[1], 'href=') !== false) {
			//add to styles
			$styles[] = $match[0];
			//remove style
			$match[0] = '';
		}
		//return
		return $match[0];
	}, $html);
	//scan scripts
	$html = preg_replace_callback('/<script([^\>]*)>(.*)<\/script>/imsU', function($match) use(&$scripts) {
		//set vars
		$check = str_replace(' ', '', $match[1]);
		$check = str_replace('type="text/javascript"', '', $check);
		//can modify?
		if(stripos($check, 'type=') === false || stripos($check, 'text/javascript') !== false || stripos($check, 'module') !== false) {
			//has src?
			if(strpos($check, 'src=') !== false) {
				if(stripos($check, ' defer') === false) {
					$match[0] = str_replace('<script ', '<script defer ', $match[0]);
				}
			} else {
				if(stripos($check, 'type=') === false && stripos($match[2], '(') !== false) {
					$match[0] = str_replace(' type="text/javascript"', '', $match[0]);
					$match[0] = str_replace('<script ', '<script type="module" ', $match[0]);
					$match[0] = str_replace('<script>', '<script type="module">', $match[0]);
				}
			}
			//add to scripts
			$scripts[] = $match[0];
			//remove script
			$match[0] = '';
		}
		//return
		return $match[0];
	}, $html);
	//add loader
	$html = preg_replace('/<body([^\>]*)>/i', '<body$1>' . "\n" . '<div id="splash"><div class="spinner"></div></div>', $html);
	$html = str_replace('</head>', file_get_contents(__DIR__ . '/tpl/splash.tpl') . "\n" . '</head>', $html);
	//add canonical?
	if($post && stripos($html, 'rel="canonical"') === false) {
		$link = '<link rel="canonical" href="' . get_permalink($post->ID) . '">';
		$html = str_replace("</title>", "</title>\n" . $link, $html);
	}
	//move styles?
	if(!empty($styles)) {
		$html = str_replace('</head>', implode("\n", $styles) . "\n" . '</head>', $html);
	}
	//move sscripts?
	if(!empty($scripts)) {
		$html = str_replace('</body>', implode("\n", $scripts) . "\n" . '</body>', $html);
	}
	//remove excess new lines
	$html = preg_replace("/\n{3,}/m", "\n\n", $html);
	//return
	return trim($html);
});