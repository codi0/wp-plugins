<?php

/*
Plugin Name: Codi Uninstaller
Description: Uninstall WordPress with just a couple of clicks!
Version: 1.0.1
Author: codi0
Author URI: https://github.com/codi-si/wp
*/

defined('ABSPATH') or die;


//create admin menu
function codi_uninstaller_admin() {
	//set vars
	$path = explode('/plugins/', __FILE__)[1];
	$page = pathinfo(__FILE__, PATHINFO_FILENAME);
	//create menu
	add_options_page(__('Uninstaller'), __('Uninstaller'), 'manage_options', $page, 'codi_uninstaller_admin_options');
	//register settings link
	add_filter('plugin_action_links_' . $path, 'codi_uninstaller_admin_link');
}

//display admin options
function codi_uninstaller_admin_options() {
	global $wpdb;
	//set vars
	$errors = [];
	$page = pathinfo(__FILE__, PATHINFO_FILENAME);
	$tables = isset($_POST['delete-tables']) ? (int) $_POST['delete-tables'] : 0;
	$files = isset($_POST['delete-files']) ? (int) $_POST['delete-files'] : 0;
	//process request?
	if(isset($_POST['process']) && $_POST['process'] === $page && check_admin_referer($page)) {
		//tables option selected?
		if(!$errors && ($tables < 1 || $tables > 3)) {
			$errors[] = __("Please select whether to delete your WordPress database tables, in order to continue");
		}
		//files option selected?
		if(!$errors && ($files < 1 || $files > 3)) {
			$errors[] = __("Please select whether to delete your WordPress files, in order to continue");
		}
		//anything to delete?
		if(!$errors && $tables == 1 && $files == 1) {
			$errors[] = __("Uninstall aborted, you don't seem to want to delete anything!");
		}
		//delete files test?
		if(!$errors && $files > 1 && !codi_uninstaller_delete_files_test()) {
			$errors[] = __("Unable to delete files automatically. Please select 'do not delete files' and then delete the files manually after uninstalling");
		}
		//delete tables?
		if(!$errors && $tables == 2 && !codi_uninstaller_delete_tables()) {
			$errors[] = __("Uninstall aborted, unable to delete database tables");
		}
		//delete config?
		if(!$errors && $files == 2) {
			codi_uninstaller_delete_config();
		}
		//delete files?
		if(!$errors && $files == 3) {
			codi_uninstaller_delete_files();
		}
		//redirect user?
		if(!$errors) {
			wp_safe_redirect(home_url());
			exit();
		}
	}
	//generate html
	echo '<div class="wrap">' . "\n";
	echo '<h2>' . __('Uninstall WordPress') . ' <small>(by <a href="https://github.com/codi-si/wp" target="_blank">codi0</a>)</small></h2>' . "\n";
	//has errors?
	if($errors) {
		echo '<div id="message" class="error">' . "\n";
		echo implode('<br>', $errors) . "\n";
		echo '</div>' . "\n";
	}
	//db tables html
	echo '<h3>' . __('Database Tables') . '</h3>' . "\n";
	echo '<form method="post" onsubmit="return confirm(\'' . __('Are you sure you want to uninstall WordPress?') . '\');">' . "\n";
	echo '<input type="hidden" name="process" value="' . $page . '">' . "\n";
	wp_nonce_field($page);
	echo '<p><input type="radio" name="delete-tables" value="1"' . ($tables == 1 ? ' checked="checked"' : '') . '> ' . __('Do not delete any database tables') . '</p>' . "\n";
	echo '<p><input type="radio" name="delete-tables" value="2"' . ($tables == 2 ? ' checked="checked"' : '') . '>' . __('Delete WordPress database tables') . '</p>' . "\n";
	//php files html
	echo '<h3 style="margin-top:30px;">' . __('WordPress Files') . '</h3>' . "\n";
	echo '<p><input type="radio" name="delete-files" value="1"' . ($files == 1 ? ' checked="checked"' : '') . '>' . __('Do not delete any files') . '</p>' . "\n";
	echo '<p><input type="radio" name="delete-files" value="2"' . ($files == 2 ? ' checked="checked"' : '') . '>' . __('Delete wp-config.php file only') . '</p>' . "\n";
	echo '<p><input type="radio" name="delete-files" value="3"' . ($files == 3 ? ' checked="checked"' : '') . '>' . __('Delete all files in the WordPress directory') . '</p>' . "\n";
	//submit form
	echo '<div class="submit"><input type="submit" class="button button-primary" value="' . __('Uninstall Now') . '">' . "\n";
	echo '</form>' . "\n";
	echo '</div>' . "\n";
}

//display plugin settings link
function codi_uninstaller_admin_link($links) {
	//set vars
	$page = pathinfo(__FILE__, PATHINFO_FILENAME);
	//create link
	$links[] = '<a href="admin.php?page=' . $page . '">' . __('Settings') . '</a>';
	//return
	return $links;
}

//delete tables
function codi_uninstaller_delete_tables() {
	global $wpdb;
	//query table names
	if(!$tables = $wpdb->get_results("SHOW TABLES FROM " . DB_NAME)) {
		return false;
	}
	//loop through tables
	foreach($tables as $table) {
		$table = array_values((array) $table);
		if(empty($wpdb->prefix) || strpos($table[0], $wpdb->prefix) === 0) {
			$wpdb->query("DROP TABLE " . $table[0]);
		}
	}
	//success
	return true;
}

//delete config
function codi_uninstaller_delete_config() {
	//is in root dir?
	if(is_file(ABSPATH . 'wp-config.php')) {
		return @unlink(ABSPATH . 'wp-config.php');
	}
	//is outside root dir?
	if(is_file(dirname(ABSPATH) . '/wp-config.php')) {
		return @unlink(dirname(ABSPATH) . '/wp-config.php');
	}
}

//delete files
function cod_uninstaller_delete_files($source=null) {
	//set vars
	$func = __FUNCTION__;
	$source = $sources ? rtrim($source, '/') : ABSPATH;
	//is this a dir?
	if(is_dir($source)) {
		//loop through all files
		if($files = @glob($source . '/*')) {
			//delete all contents
			foreach($files as $file) {
				$func($file);
			}
		}
		//delete dir
		return @rmdir($source); 
	}
	//delete file
	return @unlink($source);
}

//delete files test
function codi_uninstaller_delete_files_test($dir=null) {
	//set vars
	$result = false;
	//format directory
	$dir = $dir ? $dir : dirname(__FILE__);
	$dir = rtrim($dir, "/");
	//begin test
	if(function_exists('getmyuid') && function_exists('fileowner')) {
		//temp file name
		$temp_file = $dir . "/file-test-" . time();
		//attempt' to create file
		if($fp = @fopen($temp_file, 'w')) {
			//check ownership
			if(getmyuid() == fileowner($temp_file)) {
				$result = true;
			}
			@fclose($fp);
			@unlink($temp_file);
		}
	}
	return $result;
}

//init
add_action('admin_menu', 'codi_uninstaller_admin');