<?php

//create planner post
function codi_planner_ajax_create() {
	//user data
	$user = wp_get_current_user();
	$nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
	//post data
	$data = [
		'post_type' => CODI_PLANNER_POST_TYPE,
		'post_status' => 'publish',
		'post_title' => isset($_POST['post_title']) ? $_POST['post_title'] : '',
		'post_content' => '',
		'post_parent' => isset($_POST['post_parent']) ? (int) $_POST['post_parent'] : '',
		'menu_order' => isset($_POST['menu_order']) ? $_POST['menu_order'] : 99,
		'post_author' => $user ? $user->ID : 0,
	];
	//comment data
	$comment = [
		'comment_post_ID' => 0,
		'comment_content' => isset($_POST['post_content']) ? $_POST['post_content'] : '',
		'user_id' => $data['post_author'],
	];
	//invalid nonce?
	if(!wp_verify_nonce($nonce, "planner_nonce")) {
		wp_send_json_error([ 'error' => 'nonce' ]);
	}
	//user has permission?
	if(!$user || !current_user_can('create_posts')) {
		wp_send_json_error([ 'error' => 'denied' ]);
	}
	//empty input?
	if(!$data['post_title'] || !$comment['comment_content'] || !is_int($data['post_parent'])) {
		wp_send_json_error([ 'error' => 'empty' ]);
	}
	//dupe title?
	if(codi_planner_is_dupe($data)) {
		wp_send_json_error([ 'error' => 'dupe' ]);
	}
	//insert post?
	if(!$postId = wp_insert_post($data)) {
		wp_send_json_error([ 'error' => 'insert' ]);
	}
	//set ID
	$data['ID'] = $postId;
	//set initial status
	codi_planner_set_status($data, 'suggested');
	//insert first comment?
	if($comment['comment_content']) {
		$comment['comment_post_ID'] = $postId;
		wp_insert_comment($comment);
	}
	//send email?
	if(!current_user_can('edit_others_posts')) {
		//set vars
		$subject = "New planner suggestion";
		$message = "Title: " . $data['post_title'] . "\nUser: " . $user->user_login . "\n\n" . get_permalink($postId);
		//send emails
		foreach(codi_planner_notification_emails('post') as $to) {
			wp_mail($to, $subject, $message);
		}
	}
	//send success
	wp_send_json_success([ 'ID' => $postId ]);
}

//create planner post
function codi_planner_ajax_edit() {
	//user data
	$nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
	//post data
	$postId = isset($_POST['ID']) ? (int) $_POST['ID'] : 0;
	$post = $postId ? get_post($postId) : null;
	$data = [ 'ID' => $postId ];
	$meta = [];
	//check for additional data
	foreach([ 'post_title', 'post_content', 'post_parent', 'menu_order', 'planner_assigned', 'planner_status' ] as $key) {
		//data exists?
		if(isset($_POST[$key]) && $_POST[$key] !== '') {
			//is meta?
			if(strpos($key, 'planner') === 0) {
				$meta[$key] = $_POST[$key];
			} else {
				$data[$key] = $_POST[$key];
			}
		}
	}
	//invalid nonce?
	if(!wp_verify_nonce($nonce, "planner_nonce")) {
		wp_send_json_error([ 'error' => 'nonce' ]);
	}
	//valid post?
	if(!$post || !codi_planner_is_post($post)) {
		wp_send_json_error([ 'error' => 'post' ]);
	}
	//user has permission?
	if(!codi_planner_user_can_edit($post)) {
		wp_send_json_error([ 'error' => 'denied' ]);
	}
	//valid title?
	if(isset($data['post_title'])) {
		//is empty?
		if(!$data['post_title']) {
			wp_send_json_error([ 'error' => 'empty' ]);
		}
		//is dupe?
		if(codi_planner_is_dupe($data)) {
			wp_send_json_error([ 'error' => 'dupe' ]);
		}
	}
	//update post?
	if(!wp_update_post($data)) {
		wp_send_json_error([ 'error' => 'update' ]);
	}
	//update meta?
	if(current_user_can('edit_others_posts')) {
		//loop through meta
		foreach($meta as $key => $val) {
			//get function call
			$function = 'codi_planner_set_' . str_replace('planner_', '', $key);
			//function exists?
			if(function_exists($function)) {
				$function($post, $val);
			}
		}
	}
	//send success
	wp_send_json_success([ 'ID' => $postId ]);
}

//submit post for review
function codi_planner_ajax_submit() {
	//set vars
	$id = isset($_POST['ID']) ? (int) $_POST['ID'] : 0;
	$nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
	$post = $id ? get_post($id) : null;
	//invalid request?
	if(!wp_verify_nonce($nonce, "planner_nonce")) {
		wp_send_json_error([ 'error' => 'nonce' ]);
	}
	//valid post?
	if(!$post || !codi_planner_is_post($post)) {
		wp_send_json_error([ 'error' => 'post' ]);
	}
	//user has permission?
	if(!codi_planner_user_can_edit($post)) {
		wp_send_json_error([ 'error' => 'denied' ]);
	}
	//link created?
	if(!$linkedId = codi_planner_create_linked($post)) {
		wp_send_json_error([ 'error' => 'link' ]);
	}
	//send success
	wp_send_json_success([ 'ID' => $id, 'linkedId' => $linkedId ]);
}

//sort planner posts
function codi_planner_ajax_sort() {
	//set vars
	$count = 0;
	$nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
	$ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
	//invalid request?
	if(!wp_verify_nonce($nonce, "planner_nonce")) {
		wp_send_json_error([ 'error' => 'nonce' ]);
	}
	//user has permission?
	if(!current_user_can('create_posts')) {
		wp_send_json_error([ 'error' => 'denied' ]);
	}
	//query posts
	$posts = codi_planner_get_posts([
		'include' => $ids,
		'fields' => 'ids',
	]);
	//IDs match?
	if(!$ids || count($posts) != count($ids)) {
		wp_send_json_error([ 'error' => 'mismatch' ]);
	}
	//loop through IDs
	foreach($ids as $id) {
		wp_update_post([ 'ID' => (int) $id, 'menu_order' => ++$count ]);
	}
	//send success
	wp_send_json_success();
}

//add hooks
add_action('wp_ajax_planner_create', 'codi_planner_ajax_create');
add_action('wp_ajax_planner_edit', 'codi_planner_ajax_edit');
add_action('wp_ajax_planner_submit', 'codi_planner_ajax_submit');
add_action('wp_ajax_planner_sort', 'codi_planner_ajax_sort');