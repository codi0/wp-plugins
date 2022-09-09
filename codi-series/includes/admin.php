<?php

//add admin column name
function codi_series_admin_column_name($columns) {
	//set vars
	$new = [];
	//loop through columns
	foreach($columns as $key => $column) {
		//add existing column
		$new[$key] = $column;
		//insert series column?
		if($key === 'categories') {
			$new['series'] = __('Series');
		}
	}
	//return
	return $new;
}

//add admin column content
function codi_series_admin_column_content($column) {
	global $post;
	//is series column?
	if($column === 'series') {
		//part of series?
		if($series = get_series($post->ID)) {
			echo '<a href="' . esc_url(admin_url('edit.php?series=' . $series->slug)) . '">' . esc_html($series->name) . '</a>';
		} else {
			echo __('n/a');
		}
	}
}

//add admin filter
function codi_series_admin_filter() {
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
add_filter('manage_edit-post_columns', 'codi_series_admin_column_name');
add_action('manage_post_posts_custom_column', 'codi_series_admin_column_content', 2);
add_action('restrict_manage_posts', 'codi_series_admin_filter');