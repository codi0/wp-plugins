<?php

/*
Plugin Name: Codi Restrict
Description: Restrict post content by user role
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/


//define constants
define('CODI_RESTRICT_PLUGIN_FILE', __FILE__);
define('CODI_RESTRICT_PLUGIN_NAME', basename(__DIR__));


/* PUBLIC API */

//check whether content accessible
function codi_restrict_rules($post_id = null) {
	global $wp_roles, $post;
	//static vars
	static $_cache = [];
	//local vars
	$res = null;
	$post_id = intval($post_id) ?: ($post ? $post->ID : 0);
	//valid page?
	if($post_id > 0 && is_singular()) {
		//run check?
		if(!isset($_cache[$post_id])) {
			//set vars
			$user = wp_get_current_user();
			$current_roles = $user ? $user->roles : [];
			$rules = get_post_meta($post_id, '_codi_restrict', true) ?: null;
			//sest default state
			$_cache[$post_id] = $rules;
			//process rules?
			if(!empty($rules)) {
				//anon only?
				if($rules['who'] === 'anonymous' && !is_user_logged_in()) {
					$_cache[$post_id] = null;
				}
				//members only?
				if($rules['who'] === 'members' && is_user_logged_in()) {
					$_cache[$post_id] = null;
				}
				//loop through roles
				foreach($rules['roles'] as $role) {
					//match found?
					if(in_array($role, $current_roles)) {
						$_cache[$post_id] = null;
						break;
					}
				}
			}
		}
		//get value
		$res = $_cache[$post_id];
	}
	//return
	return apply_filters(__FUNCTION__, $res, $post_id);
}

//get restricted message
function codi_restrict_message() {
	global $post;
	//set default message
	if(is_user_logged_in()) {
		$message = '<p class="restricted">Please <a href="' . home_url() . '">upgrade your membership</a> to continue.</p>';
	} else {
		$message = '<p class="restricted">Please <a href="' . wp_login_url($_SERVER['REQUEST_URI']) . '">sign in</a> to continue.</p>';
	}
	//return
	return apply_filters(__FUNCTION__, $message, $post);
}


/* FRONTEND HOOKS */

//redirect from restricted content
function codi_restrict_redirect() {
	//can access?
	if(!($rules = codi_restrict_rules())) {
		return;
	}
	//can redirect?
	if(!in_array($rules['type'], [ 'login', 'redirect' ])) {
		return;
	}
	//filter url
	$url = apply_filters(__FUNCTION__, $rules['action'] ?: wp_login_url());
	//target is login?
	if(stripos($url, 'login') !== false) {
		//add redirect?
		if(stripos($url, 'redirect') === false) {
			//update url
			$url = add_query_arg('redirect_to', rawurlencode($_SERVER['REQUEST_URI']), $url);
		}
	}
	//add $_GET?
	if($_GET && stripos($url, 'redirect') === false) {
		//set args
		$args = $_GET;
		//remove redirect?
		if(isset($args['redirect_to'])) {
			unset($args['redirect_to']);
		}
		//update url
		$url = add_query_arg(array_map('rawurlencode', $args), $url);
	}
	//redirect?
	if(!wp_doing_ajax()) {
		wp_safe_redirect($url);
		exit();
	}
}

//add message to restricted content
function codi_restrict_the_content($content) {
	//can access?
	if(!($rules = codi_restrict_rules())) {
		return $content;
	}
	//can show message?
	if($rules['type'] !== 'message') {
		return $content;
	}
	//set vars
	$newContent = array();
	$paras = explode('</p>', $content);
	//create array
	for($i=0; $i < $rules['action']; $i++) {
		if(isset($paras[$i])) {
			$newContent[] = $paras[$i];
		}
	}
	//return
	return implode('</p>', $newContent) . codi_restrict_message();
}


/* ADMIN HOOKS */

//add meta box
function codi_restrict_metabox_add() {
	//can restrict?
	if(!current_user_can('edit_others_posts')) {
		return;
	}
	//get post types
    $post_types = get_post_types(array( 'public' => true));
	//loop through post types
    foreach($post_types as $type) {
		//register meta box
        add_meta_box('codi_restrict', __('Restrict access'), 'codi_restrict_metabox_content', $type, 'side');
	}
}

//create meta box content
function codi_restrict_metabox_content($post) {
	global $wp_roles;
	//set vars
	$rules = get_post_meta($post->ID, '_codi_restrict', true) ?: [];
	//css rules
	echo '<style>' . "\n";
	echo '#codi_restrict_type_wrap, #codi_restrict_action_wrap { display: none; }' . "\n";
	echo '#codi_restrict .main { display:block; font-weight:600; margin-bottom:2px; }' . "\n";
	echo '#codi_restrict [type="text"], #codi_restrict select { width:85%; }' . "\n";
	echo '</style>' . "\n";
	//add nonce field
	wp_nonce_field('codi_restrict_metabox', 'codi_restrict_metabox_nonce');
	//who can see
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_who">Who can access this page?</label>' . "\n";
	echo '<select name="codi_restrict_who" id="codi_restrict_who">' . "\n";
	foreach([ 'everyone' => 'Everyone', 'anonymous' => 'Only anonymous users', 'members' => 'Only logged in users', 'roles' => 'Only specific user roles', 'none' => 'No one' ] as $k => $v) {
		$selected = ($rules && $rules['who'] === $k) ? ' selected' : '';
		echo '<option value="' . $k .'"' . $selected . '>' . $v . '</option>' . "\n";
	}
	echo '</select>' . "\n";
	echo '</p>' . "\n";
	//user roles
	echo '<div id="codi_restrict_roles">' . "\n";
	foreach($wp_roles->roles as $role => $meta) {
		//checkbox vars
		$id = 'codi_restrict_roles_' . $role;
		$checked = ($rules && in_array($role, $rules['roles'])) ? ' checked' : '';
		//checkbox html
		echo '<label for="' . $id . '">' . "\n";
		echo '<input type="checkbox" name="codi_restrict_roles[]" value="' . $role . '" id="' . $id . '"' . $checked . '> ' . "\n";
		echo $meta['name'] . "\n";
		echo '</label>' . "\n";
		echo '<br>' . "\n";
	}
	echo '</div>' . "\n";
	//restrict action
	echo '<div id="codi_restrict_type_wrap">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_type">What happens to restricted users?</label>' . "\n";
	echo '<select name="codi_restrict_type" id="codi_restrict_type">' . "\n";
	foreach([ 'login' => 'Redirect to login', 'redirect' => 'Redirect to another page', 'message' => 'Display restriction message' ] as $k => $v) {
		$selected = ($rules && $rules['type'] === $k) ? ' selected' : '';
		echo '<option value="' . $k . '"' . $selected . '>' . $v . '</option>' . "\n";
	}
	echo '</select>' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	echo '<div id="codi_restrict_action_wrap">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_action">Enter redirect link:</label>' . "\n";
	echo '<input type="text" name="codi_restrict_action" id="codi_restrict_action" value="' . ($rules ? $rules['action'] : '') . '">' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		//helper function
		var updateUi = function(trigger = null) {
			//set vars
			var whoVal = jQuery('#codi_restrict_who').val();
			var typeVal = jQuery('#codi_restrict_type').val();
			var showRoles = (whoVal === 'roles');
			var showMore = (whoVal !== 'everyone');
			var isLogin = (typeVal === 'login');
			var isRedirect = (typeVal === 'redirect');
			//display type?
			jQuery('#codi_restrict_type_wrap').css('display', showMore ? 'block' : 'none');
			//display roles?
			jQuery('#codi_restrict_roles').css('display', showRoles ? 'block' : 'none');
			//display action?
			jQuery('#codi_restrict_action_wrap').css('display', showMore && !isLogin ? 'block' : 'none');
			jQuery('#codi_restrict_action_wrap label').text(isRedirect ? 'Enter redirect link:' : 'Display after X paragraphs:');
			//clear action field?
			if(trigger === 'type') {
				jQuery('#codi_restrict_action').val('');
			}
		};
		//set initial
		updateUi();
		//select who
		jQuery('#codi_restrict_who').on('change', function(e) { updateUi('who') });
		jQuery('#codi_restrict_type').on('change', function(e) { updateUi('type') });
	});
	</script>
	<?php
}

//save post meta data
function codi_restrict_metabox_save($post_id) {
	global $wp_roles;
	//set vars
	$data = [];
	$types = [ 'login', 'redirect', 'message' ];
	$who = [ 'everyone', 'anonymous', 'members', 'roles', 'none' ];
	//is valid post?
	if(empty($_POST)) {
		return $post_id;
	}
	//skip autosave?
	if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { 
		return $post_id;
	}
	//valid nonce?
	if(!isset($_POST['codi_restrict_metabox_nonce']) || !wp_verify_nonce($_POST['codi_restrict_metabox_nonce'], 'codi_restrict_metabox')) {
		return $post_id;
	}
	//has permission?
	if($_POST['post_type'] === 'page') {
		if(!current_user_can('edit_page', $post_id)) {
			return $post_id;
		}
	} else {
		if(!current_user_can('edit_post', $post_id)) {
			return $post_id;
		}
	}
	//valid who?
	if(!isset($_POST['codi_restrict_who']) || !in_array($_POST['codi_restrict_who'], $who)) {
		return $post_id;
	}
	//valid type?
	if(!isset($_POST['codi_restrict_type']) || !in_array($_POST['codi_restrict_type'], $types)) {
		return $post_id;
	}
	//process data?
	if($_POST['codi_restrict_who'] !== 'everyone') {
		//set data
		$data['who'] = sanitize_text_field($_POST['codi_restrict_who']);
		$data['type'] = sanitize_text_field($_POST['codi_restrict_type']);
		$data['roles'] = [];
		$data['action'] = '';
		//check url path
		$path = parse_url($_POST['codi_restrict_action'], PHP_URL_PATH);
		//is url?
		if($data['type'] === 'redirect') {
			$data['action'] = esc_url_raw($_POST['codi_restrict_action']) ?: '/';
		} else if($data['type'] === 'message') {
			$data['action'] = is_numeric($_POST['codi_restrict_action']) ? intval($_POST['codi_restrict_action']) : 2;
		}
		//check roles?
		if($data['who'] === 'roles' && $_POST['codi_restrict_roles']) {
			//loop through roles
			foreach($_POST['codi_restrict_roles'] as $k => $v) {
				//role exists?
				if(!$wp_roles->is_role($v)) {
					unset($_POST['codi_restrict_roles'][$k]);
					continue;
				}
			}
			//set roles
			$data['roles'] = $_POST['codi_restrict_roles'];
		}
	}
	//save data?
	if(!empty($data)) {
		update_post_meta($post_id, '_codi_restrict', $data);
	} else {
		delete_post_meta($post_id, '_codi_restrict');
	}
}

//hide from sitemap
function codi_restrict_sitemap_query($args) {
	//set meta?
	if(!isset($args['meta_query'])) {
		$args['meta_query'] = [];
	}
	//hide restricted posts
	$args['meta_query'][] = [
		'key' => '_codi_restrict',
		'compare' => 'NOT EXISTS',
	];
	//return
	return $args;
}

//redirect login form
function codi_restrict_login_form() {
	//is login page?
	if(isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {	
		//get action
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
		//exclude actions
		$exclude = [ '', 'rp', 'lostpassword' ];
		//is logged in?
		if(is_user_logged_in() && !wp_doing_ajax() && in_array($action, $exclude)) {
			//redirect to admin home
			wp_safe_redirect(admin_url());
			exit();
		}
	}
}


/* INIT */

add_action('wp', 'codi_restrict_redirect');
add_filter('the_content', 'codi_restrict_the_content', 99);
add_action('add_meta_boxes', 'codi_restrict_metabox_add');
add_action('save_post', 'codi_restrict_metabox_save');
add_action('edit_attachment', 'codi_restrict_metabox_save');
add_filter('wp_sitemaps_posts_query_args', 'codi_restrict_sitemap_query');
add_action('init', 'codi_restrict_login_form');