<?php

//notify users of new comment
function codi_planner_new_comment($comment_ID, $status, $comment) {
	//needs sending?
	if(current_user_can('edit_others_posts')) {
		return;
	}
	//set vars
	$user = wp_get_current_user();
	$post = get_post($comment['comment_post_ID']);
	//is planner?
	if(!$post || $post->post_type !== CODI_PLANNER_POST_TYPE) { 
		return;
	}
	//is first comment?
	if(get_comments_number($post->ID) <= 1) {
		return;
	}
	//email message
	$subject = "New planner comment: " . $post->post_title;
	$message = "By " . $user->user_login . "\n\n" . $comment['comment_content'] . "\n\n" . get_permalink($post->ID) . '#comment-' . $comment_ID;
	//loop through emails
	foreach(codi_planner_notification_emails('comment') as $to) {
		//skip commenter
		if($to !== $user->user_email) {
			wp_mail($to, $subject, $message);
		}
	}
}

//add hooks
add_action('comment_post', 'codi_planner_new_comment', 10, 3);