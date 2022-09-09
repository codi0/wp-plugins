<?php

//get planner roles
function codi_planner_get_roles() {
	return apply_filters('planner_roles', []);
}

//get planner users
function codi_planner_get_users() {
	//set vars
	$roles = codi_planner_get_roles();
	$users = get_users([ 'role__in' => $roles ]);
	//query users
	return apply_filters('planner_users', $users);
}

//can user edit post
function codi_planner_user_can_edit($post, $user = null) {
	//set vars
	$canEdit = false;
	$user = $user ?: wp_get_current_user();
	$assigned = codi_planner_get_assigned($post);
	//is editor?
	if($user->has_cap('edit_others_posts')) {
		$canEdit = true;
	} else {
		$canEdit = ($assigned && $user && $user->ID == $assigned->ID);
	}
	//return
	return apply_filters('planner_can_edit', $canEdit, $user, $assigned);
}

//notification emails
function codi_planner_notification_emails($type = null) {
	//set vars
	$emails = [];
	$users = get_users([ 'role' => 'administrator' ]);
	//loop through results
	foreach($users as $u) {
		$emails[$u->ID] = $u->user_email;
	}
	//sort
	ksort($emails);
	//return
	return apply_filters('planner_notification_emails', $emails, $type);
}