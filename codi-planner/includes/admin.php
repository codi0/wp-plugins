<?php

//add meta box
function codi_planner_add_metabox() {
	//register meta box?
	if(current_user_can('edit_others_posts')) {
		add_meta_box('codi_planner', __('Planner'), 'codi_planner_render_metabox', CODI_PLANNER_POST_TYPE, 'side');
	}
}

//render meta box
function codi_planner_render_metabox($post) {
	//set vars
	$status = codi_planner_get_status($post);
	$assigned = codi_planner_get_assigned($post);
	//add nonce field
	wp_nonce_field('codi_planner_metabox', 'codi_planner_metabox_nonce');
	//add status field
	echo '<p>';
	echo '<label for="planner_status" style="display:block; font-weight:600; margin-bottom:2px;">' . __('Status') . '</label>';
	echo '<select name="planner_status" id="planner_status" style="width:100%;">';
	foreach(codi_planner_get_statuses() as $val) {
		echo '<option value="' . $val . '" ' . selected($val, $status) . '>' . ucfirst($val) . '</option>';
	}
	echo '</select>';
	echo '</p>';
	//add assigned field?
	echo '<p>';
	echo '<label for="planner_assigned" style="display:block; font-weight:600; margin-bottom:2px;">' . __('Assigned to') . '</label>';
	echo '<select name="planner_assigned" id="planner_assigned" style="width:100%;">';
	echo '<option value="0">-- ' . __('No one assigned') . ' --</option>';
	foreach(codi_planner_get_users() as $user) {
		echo '<option value="' . $user->ID . '" ' . selected($user->ID, $assigned) . '>' . $user->user_login . '</option>';
	}
	echo '</select>';
	echo '</p>';
}

//save post meta data
function codi_planner_save_metabox($post_id, $post, $update) {
	//set vars
	$assigned = 0;
	//is valid post?
	if(empty($_POST)) {
		return;
	}
	//is autosave?
	if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { 
		return;
	}
	//is planner post
	if(codi_planner_is_post($post)) {
		return;
	}
	//valid nonce?
	if(!isset($_POST['codi_planner_metabox_nonce']) || !wp_verify_nonce($_POST['codi_planner_metabox_nonce'], 'codi_planner_metabox')) {
		return;
	}
	//has permission?
	if(!current_user_can('edit_others_posts')) {
		return;
	}
	//update assigned?
	if(isset($_POST['planner_assigned']) && $_POST['planner_assigned']) {
		codi_planner_set_assigned($post, $_POST['planner_assigned']);
	}
	//update status?
	if(isset($_POST['planner_status']) && $_POST['planner_status']) {
		codi_planner_set_status($post, $_POST['planner_status']);
	}
}


//add hooks
add_action('add_meta_boxes', 'codi_planner_add_metabox');
add_action('save_post', 'codi_planner_save_metabox', 10, 3);