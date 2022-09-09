<?php

//planner post register
function codi_planner_register_post_type() {
	//already registered?
	if(in_array(CODI_PLANNER_POST_TYPE, get_post_types())) {
		return;
	}
	//register post type
	register_post_type(CODI_PLANNER_POST_TYPE, [
		'label' => __('Planner'),
		'hierarchical' => true,
		'public' => true,
		'exclude_from_search' => true,
		'has_archive' => false,
		'menu_position' => 9,
		'supports' => [ 'title', 'editor', 'comments', 'page-attributes' ],
	]);
	//hide from sitemap
	add_filter('wp_sitemaps_post_types', function($post_types) {
		//unset post type?
		if(isset($post_types[CODI_PLANNER_POST_TYPE])) {
			unset($post_types[CODI_PLANNER_POST_TYPE]);
			return $post_types;
		}
	});
}

//is planner post
function codi_planner_is_post($post) {
	return ($post && $post->post_type === CODI_PLANNER_POST_TYPE);
}

//is dupe post
function codi_planner_is_dupe($post) {
	global $wpdb;
	//set vars
	$res = false;
	$post = (object) $post;
	$type = CODI_PLANNER_POST_TYPE;
	$id = isset($post->ID) ? (int) $post->ID : 0;
	$title = isset($post->post_title) ? strtolower($post->post_title) : '';
	//run check?
	if(!empty($title)) {
		//prepare query
		$query = "SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = '%s' AND LOWER(post_title) = '%s' AND ID != %d";
		$query = $wpdb->prepare($query, [ $type, $title, $id ]);
		//run query
		$res = (bool) $wpdb->get_var($query);
	}
	//return
	return $res;
}

//get posts
function codi_planner_get_posts(array $opts=[]) {
	//set defaults
	$opts = array_merge([
		'filter' => null,
		'post_type' => CODI_PLANNER_POST_TYPE,
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'orderby' => 'menu_order',
		'order' => 'asc',
		'meta_query' => [
			[
				'key' => '_planner_status',
				'compare' => '!=',
				'value' => 'closed',
			],
		],
	], $opts);
	//is suggested?
	if($opts['filter'] === 'suggested') {
		$opts['meta_query'][] = [
			'key' => '_planner_status',
			'compare' => 'IN',
			'value' => 'suggested',
		];
	}
	//is unassigned?
	if($opts['filter'] === 'unassigned') {
		$opts['meta_query'][] = [
			'key' => '_planner_status',
			'compare' => 'IN',
			'value' => [ 'suggested', 'approved' ],
		];
	}
	//is assigned to user?
	if($opts['filter'] === 'mine') {
		$opts['meta_query'][] = [
			'key' => '_planner_assigned',
			'compare' => '=',
			'value' => get_current_user_id(),
		];
	}
	//return
	return get_posts($opts);
}

//get series
function codi_planner_get_series($seriesId=0, array $opts=[]) {
	//set series ID
	$opts['post_parent'] = $seriesId;
	//get posts
	return codi_planner_get_posts($opts);
}

//get taxonomies
function codi_planner_get_taxonomies($post = null) {
	//set vars
	$tax = [];
	//check post?
	if($post && $post->ID > 0) {
		$tax = (array) (get_post_meta($post->ID, '_planner_tax', true) ?: []);
	}
	//get defaults?
	if(empty($tax)) {
		$tax = get_object_taxonomies('post');
	}
	//return
	return apply_filters('planner_taxonomies', $tax, $post);
}

//get planner status list
function codi_planner_get_statuses() {
	//set vars
	$statuses = [ 'suggested', 'approved', 'assigned', 'submitted', 'published', 'closed' ];
	//return
	return apply_filters('planner_statuses', $statuses);
}

//get planner status
function codi_planner_get_status($post) {
	//format post
	$post = (object) $post;
	//get status
	return get_post_meta($post->ID, '_planner_status', true) ?: 'suggested';
}

//set planner status
function codi_planner_set_status($post, $status) {
	//format post
	$post = (object) $post;
	$linked = codi_planner_get_linked($post, true);
	//sync status?
	if($linked && $status !== 'deleting') {
		$status = ($linked->post_status === 'publish') ? 'published' : 'submitted';
	} else if($status !== 'rejected') {
		$status = codi_planner_get_assigned($post) ? 'assigned' : $status;
	}
	//valid status?
	if(!in_array($status, codi_planner_get_statuses())) {
		return;
	}
	//update meta
	return update_post_meta($post->ID, '_planner_status', $status);
}

//get planner assigned
function codi_planner_get_assigned($post) {
	//format post
	$post = (object) $post;
	//get user ID
	$userId = get_post_meta($post->ID, '_planner_assigned', true) ?: 0;
	//return
	return get_user_by('ID', $userId) ?: null;
}

//set planner assigned
function codi_planner_set_assigned($post, $authorId) {
	//format post
	$post = (object) $post;
	//update meta
	return update_post_meta($post->ID, '_planner_assigned', (int) $authorId);
}

//get linked post
function codi_planner_get_linked($post, $query = false) {
	//format post
	$post = (object) $post;
	//linked post found?
	if(!$linkedId = (int) get_post_meta($post->ID, '_planner_linked', true)) {
		return null;
	}
	//return
	return $query ? get_post($linkedId) : $linkedId;
}

//create post link
function codi_planner_create_linked($post) {
	//can create link?
	if(!codi_planner_is_post($post)) {
		return null;
	}
	//link already exists?
	if($id = codi_planner_get_linked($post)) {
		return $id;
	}
	//has parent?
	if(!$post->post_parent) {
		return null;
	}
	//parent found?
	if(!$parent = get_post($post->post_parent)) {
		return null;
	}
	//linked data
	$linked = [
		'post_status' => 'pending',
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
	];
	//filter linked data
	$linked = apply_filters('planner_link_create', $linked, $post);
	//insert post?
	if(!$id = wp_insert_post($linked)) {
		return null;
	}
	//set meta
	update_post_meta($post->ID, '_planner_linked', $id);
	update_post_meta($id, '_planner_linked', $post->ID);
	//check parent for taxonomy
	if(!$tax = get_post_meta($parent->ID, '_planner_tax', true)) {
		$tax = get_taxonomies();
	}
	//check taxonomies
	$tax = codi_planner_get_taxonomies($parent);
	$terms = get_terms([ 'slug' => $parent->post_name ]) ?: [];
	//loop through terms
	foreach($terms as $term) {
		//taxonomy match found?
		if(in_array($term->taxonomy, $tax)) {
			wp_set_post_terms($id, [ $term->term_id ], $term->taxonomy);
			break;
		}
	}
	//update status
	codi_planner_set_status($post, 'submitted');
	//do action
	do_action('planner_link_created', $post->ID, $id);
	//return
	return $id;
}

//sync post data
function codi_planner_sync_linked($post_id, $post, $update) {
	static $processed = [];
	//is valid?
	if(!$update || in_array($post_id, $processed)) {
		return;
	}
	//is autosave?
	if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { 
		return;
	}
	//is linked?
	if(!$linkedId = (int) get_post_meta($post_id, '_planner_linked', true)) {
		return;
	}
	//mark processed
	$processed[] = $post_id;
	//set data
	$linked = [
		'ID' => $linkedId,
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
	];
	//linked data
	$linked = apply_filters('planner_link_sync', $linked, $post);
	//update linked
	wp_update_post($linked);
}

//publish post link
function codi_planner_publish_linked($post_id) {
	//get meta
	$linked_id = (int) get_post_meta($post_id, '_planner_linked', true);
	//update status?
	if($linked_id > 0) {
		if($linked = get_post($linked_id)) {
			//update status
			codi_planner_set_status($linked, 'published');
			//do action
			do_action('planner_link_publish', $post_id, $linked_id);
		}
	}
}

//delete post link
function codi_planner_delete_linked($meta_ids, $post_id, $meta_key, $meta_value) {
	//is planner link?
	if($meta_key === '_planner_linked' && $meta_value > 0) {
		//linked post found?
		if($linked = get_post($meta_value)) {
			//delete linked meta
			delete_post_meta($meta_value, '_planner_linked');
			//sync planner status
			codi_planner_set_status($linked, 'deleting');
			//do action
			do_action('planner_link_delete', $post_id, $meta_value);
		}
	}
}

//add hooks
add_action('init', 'codi_planner_register_post_type', 99);
add_action('save_post', 'codi_planner_sync_linked', 10, 3);
add_action('publish_post', 'codi_planner_publish_linked');
add_action('delete_post_meta', 'codi_planner_delete_linked', 10, 4);