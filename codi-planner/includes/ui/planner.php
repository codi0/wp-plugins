<?php

//planner assets
function codi_planner_enqueue_assets() {
	global $post;
	//load planner assets?
	if(is_singular() && (codi_planner_is_post($post) || has_shortcode($post->post_content, 'codi_planner'))) {
		//get plugin url
		$url = plugin_dir_url(CODI_PLANNER_PLUGIN_FILE);
		$dir = plugin_dir_path(CODI_PLANNER_PLUGIN_FILE);
		//register assets
		wp_enqueue_style('planner', $url . 'assets/planner.css', [], filemtime($dir . '/assets/planner.css'));
		wp_enqueue_script('planner', $url . 'assets/planner.js', [ 'jquery-ui-sortable' ], filemtime($dir . '/assets/planner.js'));
	}
}

//render list view
function codi_planner_render_list(array $opts=[]) {
	global $post;
	//get permission
	$permission = isset($opts['permission']) ? $opts['permission'] : 'create_posts';
	//can access?
	if(!current_user_can($permission)) {
		wp_redirect(wp_login_url());
		exit();
	}
	//set vars
	$html = '';
	$count = 0;
	$postsArr = [];
	$seriesCur = null;
	$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
	$seriesId = isset($_GET['series']) ? (int) $_GET['series'] : 0;
	//query all series
	$seriesArr = codi_planner_get_posts([ 'post_parent' => 0 ]);
	//set default ID?
	if($seriesArr && $seriesId <= 0) {
		$seriesId = $seriesArr[0]->ID;
	}
	//query posts?
	if($seriesId > 0) {
		$postsArr = codi_planner_get_posts([ 'post_parent' => $seriesId, 'filter' => $filter ]);
	}
	//filters
	$html .= '<div class="planner-filters">' . "\n";
	foreach([ '' => 'All', 'suggested' => 'Suggestions', 'unassigned' => 'Unassigned', 'mine' => 'Assigned to me' ] as $k => $v) {
		$classes = ($k == $filter) ? 'active' : '';
		$html .= '<a href="' . add_query_arg('filter', $k, $_SERVER['REQUEST_URI']) . '" class="' . $classes . '">' . $v . '</a>' . "\n";
	}
	$html .= '</div>' . "\n";
	//open wrapper
	$html .= '<div class="planner planner-list" data-nonce="' . wp_create_nonce('planner_nonce') . '">' . "\n";
	//open series
	$html .= '<div class="series">' . "\n";
	//open sorable
	$html .= '<div class="sortable">' . "\n";
	//loop through series
	foreach($seriesArr as $p) {
		//set vars
		$selected = ($seriesId == $p->ID);
		$children = codi_planner_get_posts([ 'post_parent' => $p->ID, 'fields' => 'ids', 'filter' => $filter ]);
		//add item
		$html .= '<div class="item' . ($selected ? ' active' : '') . '">' . "\n";
		$html .= '<a data-series="' . $p->ID . '" href="?series=' . $p->ID . '&filter=' . $filter . '">' . $p->post_title . '</a> (' . count($children) . ')' . "\n";
		$html .= '</div>' . "\n";
		//cache series?
		if($selected) {
			$seriesCur = $p;
		}
	}
	//close sortable
	$html .= '</div>' . "\n";
	//suggest series
	$html .= '<div class="item add">' . "\n";
	$html .= '<a>+ Suggest a series</a>' . "\n";
	$html .= '</div>' . "\n";
	//close series
	$html .= '</div>' . "\n";
	//open posts
	$html .= '<div class="posts">' . "\n";
	//has posts?
	if($postsArr) {
		//open sortable
		$html .= '<div class="sortable">' . "\n";
		$html .= '<div class="scroller">' . "\n";
		//loop through posts
		foreach($postsArr as $p) {
			//set vars
			$status = codi_planner_get_status($p);
			$assigned = codi_planner_get_assigned($p);
			$isMine = $assigned ? ($assigned->ID == get_current_user_id()) : false;
			$url = add_query_arg('back', urlencode($_SERVER['REQUEST_URI']), get_permalink($p->ID));
			//add item
			$html .= '<div class="item" data-status="' . $status . '">' . "\n";
			$html .= '<div class="name"><a href="' . $url . '" data-post="' . $p->ID . '">#' . (++$count) . ' ' . $p->post_title . '</a></div>' . "\n";
			$html .= '<div class="info">' . "\n";
			$html .= '<span class="status">' . ucfirst($status) . ($isMine ? ' to me' : '') . '</span>' . "\n";
			$html .= '<span class="notes">' . $p->comment_count . ' note' . ($p->comment_count == 1 ? '' : 's') . '</span>' . "\n";
			$html .= '</div>' . "\n";
			$html .= '</div>' . "\n";
		}
		//close sortable
		$html .= '</div>' . "\n";
		$html .= '</div>' . "\n";
	} else {
		//not found
		if($filter) {
			$html .= '<p class="none">No matching articles found in this series.</p>' . "\n";
		} else if($seriesCur) {
			$html .= '<p class="none">No articles suggested yet. Be the first!</p>' . "\n";
		} else {
			$html .= '<p class="none">Suggest a series to get started.</p>' . "\n";
		}
	}
	//add to series?
	if($seriesCur) {
		//set vars
		$url = add_query_arg('back', urlencode($_SERVER['REQUEST_URI']), get_permalink($seriesCur->ID));
		//discuss series
		$html .= '<div class="discuss">' . "\n";
		$html .= '<a href="' . $url . '">' . "\n";
		$html .= '+ Discuss this series <span class="notes">(' . $seriesCur->comment_count . ' note' . ($seriesCur->comment_count == 1 ? '' : 's') . ')</span>' . "\n";
		$html .= '</a>' . "\n";
		$html .= '</div>' . "\n";
		//add article
		$html .= '<div class="add">' . "\n";
		$html .= '<a data-series-add="' . $seriesCur->ID . '">+ Suggest an article</a>' . "\n";
		$html .= '</div>' . "\n";
	}
	//close posts
	$html .= '</div>' . "\n";
	//close wrapper
	$html .= '</div>' . "\n";
	//check planner url
	$url = get_permalink($post->ID);
	$cache = get_option('codi_planner_url');
	if(!$cache || $cache !== $url) {
		update_option('codi_planner_url', get_permalink($post->ID));
	}
	//return
	return $html;
}

//planner shortcode
function codi_planner_shortcode_list($atts, $content='') {
	return codi_planner_render_list($atts ?: []);
}

//add hooks
add_action('wp_enqueue_scripts', 'codi_planner_enqueue_assets');
add_shortcode('codi_planner', 'codi_planner_shortcode_list');