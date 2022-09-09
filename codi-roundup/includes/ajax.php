<?php

//save story
function codi_roundup_ajax_save_story() {
	//check referer
	check_ajax_referer(CODI_ROUNDUP_PLUGIN_NAME, 'nonce');
	//can save?
	if($id = codi_roundup_save_story($_POST)) {
		$res = [ 'success' => true, 'id' => $id ];
	} else {
		$res = [ 'success' => false ];
	}
	//send response
    wp_send_json($res);
}

//add hooks
add_action('wp_ajax_roundup_save_story', 'codi_roundup_ajax_save_story');