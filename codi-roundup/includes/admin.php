<?php

//menu pages
function codi_roundup_admin_menu() {
	//set vars
	$page = CODI_ROUNDUP_PLUGIN_NAME;
	$path = explode('/plugins/', CODI_ROUNDUP_PLUGIN_FILE)[1];
	//register menu option
	add_menu_page(__('Roundups'), __('Roundups'), 'edit_others_posts', $page, 'codi_roundup_admin_options', '', '52.8953');
	//register settings link
	add_filter('plugin_action_links_' . $path, 'codi_roundup_admin_link');
}

//display admin options
function codi_roundup_admin_options() {
	//set vars
	$result = null;
	$page = CODI_ROUNDUP_PLUGIN_NAME;
	$section = isset($_GET['section']) ? $_GET['section'] : '';
	$age = isset($_GET['age']) ? (int) $_GET['age'] : 7;
	$keywords = isset($_GET['key']) ? $_GET['key'] : '';
	$roundup = (isset($_GET['rup']) && strlen($_GET['rup']) > 0) ? (int) $_GET['rup'] : '';
	//is $_POST?
	if($_POST && check_admin_referer($page)) {
		//add to roundup?
		if(isset($_POST['stories']) && !$section) {
			codi_roundup_attach_stories($_POST['roundup'], $_POST['stories']);
		}
		//save feeds?
		if(isset($_POST['feeds']) && $section === 'feeds') {
			codi_roundup_option('feeds', $_POST['feeds']);
		}
		//add story?
		if(isset($_POST['link']) && $section === 'story') {
			$result = codi_roundup_save_story($_POST);
		}
		//create post?
		if(isset($_POST['roundup']) && $section === 'post') {
			//has stories?
			if($result = codi_roundup_has_stories($_POST['roundup'])) {
				wp_safe_redirect(admin_url('post-new.php?roundup=' . $_POST['roundup']));
				exit();
			}
		}
	}
	//get stories
	$stories = codi_roundup_get_stories([
		'age' => $age,
		'keywords' => $keywords,
		'roundup' => $roundup,
	]);
	//get feeds
	$feeds = codi_roundup_option('feeds');
	//generate html
	echo '<div class="wrap roundup">' . "\n";
	echo '<h2>' . __('News roundups') . ' <small>(by <a href="<small>(by <a href="https://github.com/codi-si/wp" target="_blank">codi0</a>)</small>" target="_blank">codi0</a>)</small></h2>' . "\n";
	echo '<ul class="subsubsub">' . "\n";
	echo '<li><a href="admin.php?page=' . esc_attr($page) . '"' . (!$section ? ' class="current"' : '') . '>Stories</a>(' . count($stories) . ') &nbsp;|&nbsp; </li>' . "\n";
	echo '<li><a href="admin.php?page=' . esc_attr($page) . '&section=feeds"' . ($section == 'feeds' ? ' class="current"' : '') . '>Feeds</a>(' . count($feeds) . ') &nbsp;|&nbsp; </li>' . "\n";
	echo '<li><a href="admin.php?page=' . esc_attr($page) . '&section=story"' . ($section == 'story' ? ' class="current"' : '') . '>Add story</a> &nbsp;|&nbsp; </li>' . "\n";
	echo '<li><a href="admin.php?page=' . esc_attr($page) . '&section=post"' . ($section == 'post' ? ' class="current"' : '') . '>Create post</a></li>' . "\n";
	echo '</ul>' . "\n";
	echo '<div class="clear"></div>' . "\n";
	//select section
	if($section === 'feeds') {
		//manage feeds
		echo '<form method="post">' . "\n";
		wp_nonce_field($page);
		echo '<h3>RSS feeds to import stories from <small>(one url per line)</small></h3>' . "\n";
		echo '<textarea name="feeds">' . esc_html(implode("\n", $feeds)) . '</textarea>' . "\n";
		echo '<br><input type="submit" class="button button-primary" value="' . __('Save feeds') . '">' . "\n";
		echo '</form>' . "\n";
	} else if($section === 'story') {
		//set vars
		$link = '';
		$title = '';
		$snippet = '';
		$roundup = 0;
		//show errors?
		if($result === false) {
			//set data
			$link = $_POST['link'];
			$title = $_POST['title'];
			$snippet = $_POST['snippet'];
			$roundup = $_POST['roundup'];
			echo '<div class="notice error">Please provide a valid link, title and snippet</div>' . "\n";
		}
		//show success?
		if($result > 0) {
			echo '<div class="notice success">This story has been successfully saved</div>' . "\n";
		}
		//add story
		echo '<form method="post">' . "\n";
		wp_nonce_field($page);
		echo '<table class="add-story">' . "\n";
		echo '<tr><td>Link</td><td><input type="text" name="link" value="' . esc_attr($link) . '"></td></tr>' . "\n";
		echo '<tr><td>Title</td><td><input type="text" name="title" value="' . esc_attr($title) . '"></td></tr>' . "\n";
		echo '<tr><td>Snippet</td><td><textarea name="snippet">' . esc_html($snippet) . '</textarea></td></tr>' . "\n";
		echo '<tr><td>Roundup ID</td><td><input type="text" name="roundup" value="' . esc_attr($roundup) . '"> (optional)</td></tr>' . "\n";
		echo '<tr><td></td><td><input type="submit" class="button button-primary" value="Add story"></td></tr>' . "\n";
		echo '</table>' . "\n";
		echo '</form>' . "\n";
	} else if($section === 'post') {
		//set vars
		$roundup = isset($_POST['roundup']) ? $_POST['roundup'] : '';
		//show errors?
		if($result === 0) {
			echo '<div class="notice error">This roundup ID has no stories associated with it</div>' . "\n";
		}
		//create post
		echo '<form method="post">' . "\n";
		wp_nonce_field($page);
		echo '<table class="create-post">' . "\n";
		echo '<tr><td>Roundup ID</td><td><input type="text" name="roundup" value="' . esc_attr($roundup) . '"></td></tr>' . "\n";
		echo '<tr><td></td><td><input type="submit" class="button button-primary" value="Create post"></td></tr>' . "\n";
		echo '</table>' . "\n";
		echo '</form>' . "\n";
	} else {
		//list stories
		echo '<form method="get">' . "\n";
		echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">' . "\n";
		echo '<input type="hidden" name="section" value="' . esc_attr($section) . '">' . "\n";
		echo '<div class="filters">' . "\n";
		echo '<div class="f"><span>Search:</span> <input type="text" name="key" value="' . esc_attr($keywords) . '"></div>' . "\n";
		echo '<div class="f"><span>Days:</span> <input type="text" name="age" value="' . esc_attr($age) . '" size="1"></div>' . "\n";
		echo '<div class="f"><span>Roundup:</span> <input type="text" name="rup" value="' . esc_attr($roundup) . '" size="1"></div>' . "\n";
		echo '<div class="f"><span></span> <input type="submit" class="button" value="Filter"></div>' . "\n";
		echo '</div>' . "\n";
		echo '</form>' . "\n";
		echo '<form method="post">';
		wp_nonce_field($page);
		echo '<input type="text" name="roundup" size="1">' . "\n";
		echo '<input type="submit" class="button" value="Add to roundup">' . "\n";
		echo '<table class="wp-list-table widefat striped table-view-list stories">' . "\n";
		echo '<thead>';
		echo '<tr><td class="check-column"></td><td>Story</td><td>Roundup</td></tr>';
		echo '</thead>';
		foreach($stories as $story) {
			$domain = str_replace('www.', '', parse_url($story->link, PHP_URL_HOST));
			echo '<tr>';
			echo '<td>';
			echo '<input type="checkbox" name="stories[]" value="' . esc_attr($story->id) . '">';
			echo '</td>';
			echo '<td class="item" data-story="' . esc_attr($story->id) . '">';
			echo '<div class="link"><a href="' . esc_attr($story->link) . '" target="_blank">' . esc_html($story->title) . '</a></div>';
			echo '<div class="snippet">' . esc_html($story->snippet) . '</div>';
			echo '<div class="meta">' . date('l, d F', $story->time) . ' &middot; ' . date('H:i', $story->time) . ' &middot; <span>' . esc_html($domain) . '</span></div>';
			echo '</td>';
			echo '<td>' . ($story->roundup ?: '') . '</td>';
			echo '</tr>';
		}
		echo '</table>' . "\n";
		echo '</form>' . "\n";
	}
	echo '</div>' . "\n";
}

//display plugin settings link
function codi_roundup_admin_link($links) {
	//set vars
	$page = CODI_ROUNDUP_PLUGIN_NAME;
	//create link
	$links[] = '<a href="admin.php?page=' . esc_attr($page) . '">' . __('Settings') . '</a>';
	//return
	return $links;
}

//add admin assets
function codi_roundup_admin_assets($hook) {
	//current page?
	if($hook === 'toplevel_page_codi-roundup') {
		$url = plugin_dir_url(CODI_ROUNDUP_PLUGIN_FILE) . 'assets';
		wp_enqueue_style('roundup', $url . '/admin.css', []);
		wp_enqueue_script('roundup', $url . '/admin.js', [ 'jquery', 'wp-util' ]);
	}
}

//new post template
function codi_roundup_admin_post_template($content) {
	//set vars
	$roundup = isset($_GET['roundup']) ? (int) $_GET['roundup'] : 0;
	//add template?
	if(!$content && $roundup > 0 && is_admin()) {
		$content .= '<p>Replace this text with your editorial</p>' . "\n\n";
		$content .= '<h3>Roundup stories</h3>' . "\n\n";
		$content .= '[codi_roundup id="' . $roundup . '" domain="true" date="false"]';
	}
	//return
	return $content;
}

//add hooks
add_action('admin_menu', 'codi_roundup_admin_menu');
add_action('admin_enqueue_scripts', 'codi_roundup_admin_assets');
add_filter('the_editor_content', 'codi_roundup_admin_post_template');