<?php

//db table name
function codi_roundup_table($name) {
	global $wpdb;
	return $wpdb->prefix . 'roundup_' . $name;
} 

//sync db tables
function codi_roundup_sync_db() {
	global $wpdb;
	//load dbDelta
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	//set vars
	$sql = [];
	$charset = $wpdb->get_charset_collate();
	//tables
	$story = codi_roundup_table('story');
	//story table
	$sql[] = "CREATE TABLE $story (
		id mediumint NOT NULL AUTO_INCREMENT,
		link varchar(255) NOT NULL,
		title varchar(255) NOT NULL,
		snippet text,
		time bigint NOT NULL,
		roundup mediumint NOT NULL DEFAULT '0',
		PRIMARY KEY (id),
		KEY (time),
		KEY (roundup),
		FULLTEXT KEY (title, snippet)
	) $charset;";
	//update
	dbDelta($sql);
}

//get or set roundup options
function codi_roundup_option($name, $data = null) {
	//is timestamp?
	if($name === 'timestamp') {
		//retrieve data?
		if($data === null) {
			return get_option('codi_roundup_' . $name, 0);
		}
		//format data?
		if(!is_numeric($data)) {
			$data = strtotime($data);
		}
		//save data?
		if($data > 0) {
			update_option('codi_roundup_' . $name, $data, false);
		}
		//return
		return $data ?: 0;
	}
	//is feeds?
	if($name === 'feeds') {
		//retrieve data?
		if($data === null) {
			return get_option('codi_roundup_' . $name, []);
		}
		//format data?
		if(!is_array($data)) {
			$data = trim(strip_tags($data));
			$data = str_replace("\t", " ", $data);
			$data = str_replace("\r\n", "\n", $data);
			$data = preg_replace("/\n+/", "\n", $data);
			$data = $data ? explode("\n", $data) : [];
		}
		//save data
		update_option('codi_roundup_' . $name, $data, false);
		//run cron?
		if(function_exists('codi_roundup_run_cron')) {
			codi_roundup_run_cron();
		}
		//return
		return $data ?: [];
	}
}

//get stories
function codi_roundup_get_stories(array $opts=[], $format=OBJECT) {
	global $wpdb;
	//set opts
	$opts = array_merge([
		'age' => 0,
		'keywords' => '',
		'roundup' => '',
	], $opts);
	//set vars
	$where = '';
	$params = [ 1 ];
	$table = codi_roundup_table('story');
	//set age?
	if($opts['age'] > 0) {
		$where .= ' AND time > %d';
		$params[] = time() - (86400 * $opts['age']);
	}
	//set keywords?
	if($opts['keywords']) {
		$where .= ' AND MATCH (title,snippet) AGAINST (%s)';
		$params[] = $opts['keywords'];
	}
	//set roundup?
	if(is_numeric($opts['roundup'])) {
		$where .= ' AND roundup = %d';
		$params[] = $opts['roundup'];
	}
	//prepare query
	$sql = $wpdb->prepare("SELECT * FROM $table WHERE 1=%d$where ORDER BY time DESC", $params);
	//execute
	return $wpdb->get_results($sql, $format) ?: [];
}

//attach stories
function codi_roundup_attach_stories($roundup, array $stories) {
	global $wpdb;
	//set vars
	$roundup = (int) $roundup;
	$stories = implode(',', array_map('intval', $stories));
	$table = codi_roundup_table('story');
	//update stories
	return $wpdb->query("UPDATE $table SET roundup=$roundup WHERE id IN($stories)");
}

//save story
function codi_roundup_save_story(array $data) {
	global $wpdb;
	//set vars
	$res = false;
	$table = codi_roundup_table('story');
	$id = isset($data['id']) ? (int) $data['id'] : 0;
	$canBeEmpty = [ 'roundup' ];
	$allowedFields = [
		'link' => 'esc_url_raw',
		'title' => 'sanitize_text_field',
		'snippet' => 'sanitize_text_field',
		'time' => 'intval',
		'roundup' => 'intval',
	];
	//check input
	foreach($data as $k => $v) {
		//field allowed?
		if(isset($allowedFields[$k])) {
			//sanitize value?
			if($f = $allowedFields[$k]) {
				$data[$k] = $f($v);
			}
			//can field be empty?
			if(!$data[$k] && !in_array($k, $canBeEmpty)) {
				unset($data[$k]);
			}
		} else {
			unset($data[$k]);
		}
	}
	//is insert?
	if(empty($id)) {
		//has necessary data?
		if(!isset($data['link']) || !isset($data['title']) || !isset($data['snippet'])) {
			return $res;
		}
		//set time?
		if(!isset($data['time'])) {
			$data['time'] = time();
		}
	}
	//valid link?
	if(isset($data['link']) && !wp_http_validate_url($data['link'])) {
		return $res;
	}
	//unslash
	$data = wp_unslash($data);
	//insert or update?
	if($data && $id) {
		$res = $wpdb->update($table, $data, [ 'id' => $id ]);
	} else if($data) {
		$res = $wpdb->insert($table, $data);
	}
	//return
	$res = is_numeric($res) ? $res : $id;
	return $res ?: false;
}

//check for stories
function codi_roundup_has_stories($id) {
	global $wpdb;
	//set vars
	$id = intval($id);
	$table = codi_roundup_table('story');
	//valid id?
	if(!$id) return 0;
	//execute query
	return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE roundup=$id");
}

//clean old stories
function codi_roundup_clean_stories($age=30) {
	global $wpdb;
	//set vars
	$table = codi_roundup_table('story');
	$time = time() - (86400 * $age);
	//execute query
	return $wpdb->query("DELETE FROM $table WHERE roundup=0 AND time < $time");
}

//add hooks
register_activation_hook(CODI_ROUNDUP_PLUGIN_FILE, 'codi_roundup_sync_db');