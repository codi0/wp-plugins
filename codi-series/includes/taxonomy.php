<?php

//register taxonomy
function codi_series_taxonomy_register() {
	//set vars
	$name = __('Series');
	//register
	register_taxonomy(
		'series',
		array( 'post' ),
		array(
			'hierarchical' => false,
			'label' => $name,
			'labels' => array(
				'menu_name' => $name,
				'name' => $name,
				'singular_name' => $name,
			),
			'show_ui' => true,
			'show_in_rest' => true,
			'show_admin_column' => true,
			'query_var' => true,
			'rewrite' => true,
			'meta_box_cb' => 'codi_series_taxonomy_admin_metabox',
		)
	);
}

//series archive query
function codi_series_taxonomy_post_query($query) {
	//is series archive?
	if($query->is_tax('series')) {
		$query->set('order', 'ASC');
	}
}

//filter series post title
function codi_series_taxonomy_post_title($title) {
	static $cache = [], $count = 0;
	//get post ID
	$id = get_the_ID();
	//is series atchive?
	if(is_tax('series')) {
		//use cache?
		if(isset($cache[$id])) {
			$title = $cache[$id];
		} else {
			$title = $cache[$id] = (++$count) . '. ' . $title;
		}
	}
	//return
	return $title;
}

//taxonomy metabox callback
function codi_series_taxonomy_admin_metabox($post) {
	//set vars
	$current_series = get_series($post->ID);
	$current_series_id = $current_series ? $current_series->term_id : 0;
	$taxonomy_data = get_taxonomy('series');
	$series_terms = get_terms('series', array( 'hide_empty' => false, 'orderby' => 'name', ));
	//build html
	echo '<div id="taxonomy-' . esc_attr($taxonomy_data->name) . '" class="categorydiv">' . "\n";
	echo '<label class="screen-reader-text" for="new_series_parent">' . "\n";
	echo esc_html($taxonomy_data->labels->parent_item_colon) . "\n";
	echo '</label>' . "\n";
	echo '<select name="tax_input[series]" style="width:100%; margin-top:10px;">' . "\n";
	echo '<option value="0">-- ' . __('No series selected') . ' --</option>' . "\n";
	foreach($series_terms as $series) {
		echo '<option value="' . esc_attr($series->slug) . '" ' . selected($current_series_id, $series->term_id, false) . '>' . esc_html($series->name) . '</option>' . "\n";
	}
	echo '</select>' . "\n";
	echo '<div class="add" style="margin-top:10px;">' . "\n";
	echo '<a href="edit-tags.php?taxonomy=series" target="_blank">' . __('Add new series') . ' &raquo;</a>' . "\n";
	echo '</div>' . "\n";
	echo '</div>' . "\n";
}

//taxonomy admin filter
function codi_series_taxonomy_admin_filter() {
	global $typenow, $wp_query;
	//is post type?
	if($typenow != 'post') {
		return;
	}
	//terms found?
	if(!$series_terms = get_terms('series', array( 'hide_empty' => true, 'orderby' => 'name' ))) {
		return;
	}
	//build html
	echo '<select name="series">' . "\n";
	echo '<option value="">' . __('All series') . '</option>' . "\n";
	foreach($series_terms as $series) {
		echo '<option value="' . esc_attr($series->slug) . '" ' . selected($_REQUEST['series'], $series->slug, false) . '>' . esc_html($series->name) . '</option>' . "\n";
	}
	echo '</select>' . "\n";
}

//add hooks
add_action('init', 'codi_series_taxonomy_register', 1);
add_action('the_title', 'codi_series_taxonomy_post_title');
add_action('pre_get_posts', 'codi_series_taxonomy_post_query');
add_action('restrict_manage_posts', 'codi_series_taxonomy_admin_filter');