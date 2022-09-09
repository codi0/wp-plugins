<?php

//render post content
function codi_planner_render_post($content='') {
	global $post;
	//is planner post?
	if($post && codi_planner_is_post($post)) {
		//can access?
		if(!current_user_can('create_posts')) {
			wp_redirect(wp_login_url());
			exit();
		}
		//set vars
		$content = '';
		$user = wp_get_current_user();
		$back = codi_planner_back_url();
		$assigned = codi_planner_get_assigned($post);
		$linked = codi_planner_get_linked($post, true);
		$name = $post->post_parent ? 'article' : 'series';
		//check permissions
		$canEdit = codi_planner_user_can_edit($post);
		$isEditor = current_user_can('edit_others_posts');
		//open wrapper
		$content .= '<div class="planner planner-post" data-post="' . $post->ID . '" data-nonce="' . wp_create_nonce('planner_nonce') . '">';
		//open form
		$content .= '<form method="post" name="planner-edit">';
		//show back
		$content .= '<a class="back" href="' . $back . '">&laquo; Back to planner</a>';
		//show notice?
		if($linked) {
			//is published?
			if($linked->post_status === 'publish') {
				$content .= '<div class="published">';
				$content .= 'This article has been published | <a href="' . get_permalink($linked->ID) . '" target="_blank">View article &raquo;</a>';
				$content .= '</div>';
			} else {
				$content .= '<div class="submitted">';
				$content .= 'This article has been subbmited for review | <a href="' . get_edit_post_link($linked->ID) . '" target="_blank">Review article &raquo;</a>';
				$content .= '</div>';
			}
		}
		//show title?
		if(!empty($name)) {
			$content .= '<h4>' . ucfirst($name) . ' title</h4>';
			if($canEdit) {
				$content .= '<p><input type="text" name="post_title" value="' . esc_attr($post->post_title) . '"></p>';
			} else {
				$content .= '<p>' . esc_html($post->post_title) . '</p>';
			}
		}
		//show series?
		if($post->post_parent) {
			//set vars
			$parent = get_post($post->post_parent);
			$url = add_query_arg('back', urlencode($back), get_permalink($parent->ID));
			//add html
			$content .= '<h4>Part of series</h4>';
			if($canEdit) {
				$content .= '<p>';
				$content .= '<select name="post_parent">';
				foreach(codi_planner_get_series() as $p) {
					$content .= '<option value="' . $p->ID . '" ' . selected($p->ID, $parent->ID, false) . '>' . $p->post_title . '</option>';
				}
				$content .= '</select>';
				$content .= '</p>';
			} else {
				$content .= '<p><a href="' . $url . '">' . $parent->post_title . '</a></p>';
			}
		}
		//show status?
		if(!empty($name)) {
			//set vars
			$status = codi_planner_get_status($post);
			//add html
			$content .= '<h4>' . ucfirst($name) . ' status</h4>';
			if($isEditor) {
				$content .= '<p>';
				$content .= '<select name="planner_status">';
				foreach(codi_planner_get_statuses() as $val) {
					$content .= '<option value="' . $val . '" ' . selected($val, $status, false) . '>' . ucfirst(str_replace('-', ' ', $val)) . '</option>';
				}
				$content .= '</select>';
				$content .= '</p>';
			} else {
				$content .= '<p>' . ucfirst($status) . '</p>';
			}
		}
		//show assigned?
		if($post->post_parent) {
			//show assigned?
			if($assigned || $isEditor) {
				$content .= '<h4>Assigned to</h4>';
				if($isEditor) {
					$content .= '<p>';
					$content .= '<select name="planner_assigned">';
					$content .= '<option value="0">-- ' . __('No one assigned') . ' --</option>';
					foreach(codi_planner_get_users() as $u) {
						$id = $assigned ? $assigned->ID : 0;
						$content .= '<option value="' . $u->ID . '" ' . selected($u->ID, $id, false) . '>' . $u->user_login . '</option>';
					}
					$content .= '</select>';
					$content .= '</p>';
				} else {
					$content .= '<p><a href="' . get_author_posts_url($assigned->ID) . '">' . $assigned->display_name . '</a></p>';
				}
			} else {
				$content .= '<h4>Could you write this article?</h4>' . "\n";
				$content .= '<p><a href="#respond">Let us know</a> by adding a note below.</p>';
			}
		}
		//show draft content?
		if($post->post_parent && $canEdit) {
			ob_start();
			wp_editor($post->post_content, 'post_content', [
				'media_buttons' => '',
				'editor_height' => 250,
			]);
			$content .= '<h4>Article content</h4>';
			$content .= ob_get_clean();
		}
		//show submit?
		if($canEdit) {
			$content .= '<div class="submit">';
			$content .= '<button type="submit">Save</button>';
			if(!$linked && $post->post_parent) {
				$content .= '<button name="review" class="alt">Submit post</button>';
			}
			$content .= '</div>';
		}
		//close form
		$content .= '</form>';
		//show articles?
		if(!$post->post_parent) {
			//get children
			$children = codi_planner_get_posts([
				'post_parent' => $post->ID,
			]);
			//add articles
			$content .= '<div class="articles">';
			$content .= '<h4>Suggested articles</h4>';
			if($children) {
				$content .= '<ol>';
				foreach($children as $child) {
					$url = add_query_arg('back', urlencode($back), get_permalink($child->ID));
					$content .= '<li data-post="' . $child->ID . '"><a href="' . $url . '">' . $child->post_title . '</a></li>';
				}
				$content .= '</ol>';
			} else {
				$content .= '<p>No articles suggested for this series yet</p>';
			}
			$content .= '<p class="add"><a data-series-add="' . $post->ID . '">+ Suggest an article</a></p>';
			$content .= '</div>';
		}
		//close wrapper
		$content .= '</div>';
		//start buffer
		ob_start();
	}
	//return
	return $content;
}

//back url
function codi_planner_back_url($raw=false) {
	//get url
	$url = isset($_GET['back']) ? $_GET['back'] : get_option('codi_planner_url');
	//return
	return $raw ? $url : esc_url($url);
}

//filter html
function codi_planner_filter_post_html() {
	global $post;
	//is planner post?
	if(is_singular() && codi_planner_is_post($post)) {
		//get html
		$html = ob_get_clean();
		//update comment title
		$html = preg_replace_callback('#<h3 class\=\"comments\-title\">(.*)<\/h3>#isU', function($match) {
			if(trim($match[1]) !== '') {
				return '<h3 class="comments-title">Editorial notes</h3>';
			}
		}, $html);
		//update reply title
		$html = preg_replace('#<h3 id\=\"reply\-title\" class\=\"comment\-reply\-title\">(.*)<\/h3>#isU', '<h3 id="reply-title" class="comment-reply-title">Add a note</h3>', $html);
		//remove comment field label
		$html = str_replace('<label for="comment">Comment</label>', '', $html);
		//update comment submit
		$html = str_replace('Post Comment', 'Add note', $html);
		//display
		echo $html;
	}
}

//add hooks
add_filter('the_content', 'codi_planner_render_post');
add_action('wp_footer', 'codi_planner_filter_post_html');