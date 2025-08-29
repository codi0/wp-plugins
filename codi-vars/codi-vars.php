<?php

/*
Plugin Name: Codi Template Vars
Description: Unified template variables system for shortcodes and curly-brace tokens.
Version: 1.1.0
Author: codi0
*/

defined('ABSPATH') || exit;


// Define constants
define('CODI_VARS_ALLOW_PHP', true);
define('CODI_VARS_ADMIN_CAP', 'edit_others_posts');
define('CODI_VARS_BLOCKS_WHITELIST', [ 'core/paragraph' ]);


/* CONFIG */

if(!get_option('codi_vars')) {
	update_option('codi_vars', [
		'year' => '<?= date("Y") ?>',
		'site-url' => '<?= home_url("{{url}}") ?>',
		'site-title' => '<?= get_bloginfo("name") ?>',
		'site-tagline' => '<?= get_bloginfo("description") ?>',
		'site-link' => '<a href="[var name=\'site-url\' url=\'{{url}}\']">[var name="site-title"]</a>',
		'copyright' => '&copy;[var name="year"] [var name="site-link" url="{{url}}"]',
	]);
}


/* FUNCTIONS */

function codi_vars_replace_tokens($content, array $params=[]) {
	//set vars
	$vars = get_option('codi_vars', []);
	//user placeholders
	$content = preg_replace_callback('/\{user\.([a-zA-Z0-9_]+)\}/', function($m) {
		//is logged in?
		if(!is_user_logged_in()) {
			return '';
		}
		//set vars
		$res = '';
		$key = $m[1];
		$user = wp_get_current_user();
		//is user property?
		if(isset($user->$key)) {
			$res = $user->$key;
		} else if($meta = get_user_meta($user->ID, $key, true)) {
			$res = $meta;
		}
		//return
		return is_scalar($res) ? esc_html($res) : '';
	}, $content);
	//general placeholders
	$content = preg_replace_callback('/\{([a-zA-Z0-9_\-]+)\}/', function($m) use($params, $vars) {
		//set vars
		$key = $m[1];
		//has var?
		if(!isset($vars[$key])) {
			return $m[0];
		}
		//set result
		$res = $vars[$key];
		//replace params
		foreach($params as $k => $v) {
			$res = str_replace('{{' . $k . '}}', $v !== null ? (string) $v : '', $res);
		}
		//remove empty params
		$res = preg_replace('/\{\{[a-zA-Z0-9_\-]+\}\}/', '', $res);
		//can exec php?
		if(CODI_VARS_ALLOW_PHP) {
			//contains php code?
			if(strpos($res, '<?') !== false && strpos($res, '?>') !== false) {
				ob_start();
				eval("?>$res");
				$res = ob_get_clean() ?: $res;
			}
		}
		//run shortcode?
		if(strpos($res, '[') !== false && strpos($res, ']') !== false) {
			$res = do_shortcode($res);
		}
		//re-run replacement?
		if(strpos($res, '{') !== false && strpos($res, '}') !== false) {
			$res = codi_vars_replace_tokens($res, $params);
		}
		//return
		return $res;
	}, $content);
	//return
    return $content;
}

function codi_vars_admin() {
	//can access?
	if(!current_user_can(CODI_VARS_ADMIN_CAP)) {
		wp_die('You do not have permission to access this page.');
	}
	//handle save?
	if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['codi_vars_nonce'])) {
		//check nonce
		check_admin_referer('codi_vars_save', 'codi_vars_nonce');
		//set vars
		$vars = [];
		$names = isset($_POST['vars-name'])  ? (array) wp_unslash($_POST['vars-name']) : [];
		$values = isset($_POST['vars-value']) ? (array) wp_unslash($_POST['vars-value']) : [];
		//loop through vars
        foreach($names as $index => $name) {
			//valid key?
			if(!$name = sanitize_key($name)) {
				continue;
			}
			//update value
			$vars[$name] = isset($values[$index]) ? trim($values[$index]) : '';
		}
		//save data
		update_option('codi_vars', $vars);
		//update UI
		add_settings_error('codi_vars', 'saved', 'Variables saved', 'updated');
	}
	//get current vars
	$vars = get_option('codi_vars', []);
	//output admin page
	echo '<div class="wrap">';
	echo '<h1 class="wp-heading-inline">Template Variables</h1>';
	echo '<p>Define information once and display it in multiple places; including post content, widgets and blocks.</p>';
	echo '<p>It allows you to update information that changes regularly in one place. You can use text or HTML in values.</p>';
	echo '<p><b>Example:</b> using [var name="copyright"] in a post will display ' . do_shortcode('[var name="copyright"]') . '</p>';
	echo '<br>';
	//display errors
	settings_errors('codi_vars');
	//display form
	echo '<form method="post">';
	wp_nonce_field('codi_vars_save', 'codi_vars_nonce');
	//display vars table
	echo '<table id="codi-vars-list" class="wp-list-table widefat fixed striped table-view-list posts">';
	echo '<tr><th width="200"><b>Name</b></th><th><b>Value</b></th></tr>';
	//loop through vars
	foreach($vars as $name => $value) {
		echo '<tr>';
		echo '<td><input type="text" name="vars-name[]" value="' . esc_attr($name) . '" style="width:100%;"></td>';
		echo '<td><input type="text" name="vars-value[]" value="' . esc_attr($value) . '" style="width:100%;"></td>';
		echo '</tr>';
	}
	echo '</table>';
	echo '<p><input type="submit" value="Save changes" class="button button-primary"> &nbsp; <input type="button" value="Add new" id="codi-vars-add" class="button"></p>';
	echo '</form>';
	//add row JS
	?>
	<script>
	document.getElementById('codi-vars-add').addEventListener('click', function() {
		var table = document.getElementById('codi-vars-list');
		var row = document.createElement('tr');
		row.innerHTML = '<td><input type="text" name="vars-name[]" value="" style="width:100%;"></td><td><input type="text" name="vars-value[]" value="" style="width:100%;"></td>';
		table.appendChild(row);
	});
	</script>
	<?php
	echo '</div>';
}

function codi_vars_uninstall() {
	delete_option('codi_vars');
}


/* HOOKS */

add_shortcode('var', function(array $atts) {
	//set defaults
    $atts = array_merge([ 'name' => '', 'default' => '' ], $atts);
    //extract defaults
    $name = $atts['name'];
    $default = $atts['default'];
    unset($atts['name'], $atts['default']);
    //run replacement
    $token = '{' . $name . '}';
    $res = codi_vars_replace_tokens($token, $atts);
    //use default?
    if(!$res || $res === $token) {
		$res = $default;
    }
    //return
    return $res;
});

add_filter('render_block', function($content, $block) {
	//get block name
    $name = isset($block['blockName']) ? $block['blockName'] : null;
	//can process block?
    if(CODI_VARS_BLOCKS_WHITELIST && !in_array($block['blockName'], CODI_VARS_BLOCKS_WHITELIST, true)) {
        return $content;
    }
    //process
    return codi_vars_replace_tokens($content);
}, 9999, 2);

add_filter('the_content', function($content) {
	//skip if blocks?
    if(function_exists('has_blocks') && has_blocks(get_the_ID())) {
        return $content;
    }
    //process
	return codi_vars_replace_tokens($content);
}, 9999);

add_action('admin_menu', function() {
	add_submenu_page('edit.php', __('Variables'), __('Variables'), CODI_VARS_ADMIN_CAP, 'vars', 'codi_vars_admin');
	add_submenu_page('edit.php?post_type=page', __('Variables'), __('Variables'), CODI_VARS_ADMIN_CAP, 'vars', 'codi_vars_admin');
});

register_uninstall_hook(__FILE__, 'codi_vars_uninstall');