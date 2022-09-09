<?php

//schedule cron
function codi_roundup_schedule_cron() {
	if(!wp_next_scheduled('codi_roundup_cron')) {
        wp_schedule_event(time(), 'hourly', 'codi_roundup_cron');
    }
}

//unschedule cron
function codi_roundup_unschedule_cron() {
	wp_clear_scheduled_hook('codi_roundup_cron');
}

//run cron
function codi_roundup_run_cron() {
	global $wpdb;
	//set vars
	$items = [];
	$urls = codi_roundup_option('feeds');
	$oldTime = codi_roundup_option('timestamp');
	$newTime = 0;
	//loop through urls
	foreach($urls as $url) {
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
	//order by date
	uasort($items, function($a, $b) {
		return $a['time'] < $b['time'] ? -1 : 1;
	});
	//loop through items
	foreach($items as $item) {
		//skip item?
		if($oldTime >= $item['time']) {
			continue;
		}
		//save story
		codi_roundup_save_story($item);
		//update new time?
		if($item['time'] > $newTime) {
			$newTime = $item['time'];
		}
	}
	//update timestamp?
	if($newTime > 0) {
		codi_roundup_option('timestamp', $newTime);
	}
	//delete old stories
	codi_roundup_clean_stories();
}

//add hooks
add_action('init', 'codi_roundup_schedule_cron');
add_action('codi_roundup_cron', 'codi_roundup_run_cron');
register_deactivation_hook(CODI_ROUNDUP_PLUGIN_FILE, 'codi_roundup_unschedule_cron');