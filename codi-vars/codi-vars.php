<?php

/*
Plugin Name: Codi Vars
Description: Create variables that can be used as shortcodes in post content and blocks.
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


//define constants
define('CODI_VARS_PLUGIN_FILE', __FILE__);
define('CODI_VARS_PLUGIN_NAME', basename(__DIR__));

//config vars
define('CODI_VARS_ALLOW_PHP', true);
define('CODI_VARS_BLOCKS_WHITELIST', [ 'core/paragraph' ]);
define('CODI_VARS_ADMIN_CAP', 'edit_others_posts');

//set default vars?
if(!get_option('codi_vars')) {
	//update option
	update_option('codi_vars', [
		'year' => '<?= date("Y") ?>',
		'site-url' => '<?= home_url("{{url}}") ?>',
		'site-title' => '<?= get_bloginfo("name") ?>',
		'site-tagline' => '<?= get_bloginfo("description") ?>',
		'site-link' => '<a href="[var name=\'site-url\' url=\'{{url}}\']">[var name="site-title"]</a>',
		'copyright' => '&copy;[var name="year"] [var name="site-link" url="{{url}}"]',
	]);
}

//var shortcode
add_shortcode('var', function($atts) {
	//set vars
	$res = '';
	$name = null;
	$atts = $atts ?: [];
	$vars = get_option('codi_vars');
	//has name?
	if(isset($atts['name'])) {
		$name = $atts['name'];
		unset($atts['name']);
	}
	//valid var?
	if($name && isset($vars[$name])) {
		//get result
		$res = $vars[$name];
		//replace params
		foreach($atts as $k => $v) {
			$res = str_replace('{{' . $k . '}}', $v ?: '', $res);
		}
		//remove empty params
		$res = preg_replace('/{{(.*)}}/U', '', $res);
		//allow nested shortcodes
		$res = do_shortcode($res);
		//might have php code?
		if(CODI_VARS_ALLOW_PHP && preg_match('/\?\>/', $res)) {
			//clean opening tag
			$res = str_replace('&lt;?', '<?', $res);
			//exec php
			ob_start();
			eval("?>$res");
			$res = ob_get_clean() ?: $res;
		}
	}
	//return
	return $res;
});

//block shortcode
add_shortcode('block', function($atts) {
	//cast to array
	$atts = $atts ?: [];
	//default params
	$defaults = array_merge([
		'blockName' => '',
		'attrs' => [],
		'innerHTML' => '',
		'innerContent' => [],
		'innerBlocks' => [],
	], $atts);
	//merge defaults
	foreach($defaults as $k => $v) {
		if(!isset($atts[$k])) {
			$atts[$k] = $v;
		} else if(is_array($v)) {
			$atts[$k] = $v ? json_decode($v, true) : [];
		}
	}
	//set name?
	if(isset($atts['name'])) {
		$atts['blockName'] = $atts['name'];
		unset($atts['name']);
	}
	//format name?
	if($atts['blockName']) {
		//convert camelcase
		$atts['blockName'] = strtolower(preg_replace('/([A-Z])/', '-$1', $atts['blockName']));
		//add core prefix?
		if(strpos($atts['blockName'], '/') === false) {
			$atts['blockName'] = 'core/' . $atts['blockName'];
		}
	}
	//render?
	return render_block($atts);
});

//render shortcodes in whitelisted blocks
add_filter('render_block', function($content, $block, $context) {
	//can parse shortcode?
	if(CODI_VARS_BLOCKS_WHITELIST && !in_array($block['blockName'], CODI_VARS_BLOCKS_WHITELIST)) {
		return $content;
	}
	//parse shortcode
	return preg_replace_callback('/\[(.*)\]/U', function($match) {
		return do_shortcode($match[0]);
	}, $content);
}, 9999, 3);

//add admin menu
add_action('admin_menu', function() {
	//posts submenu
	add_submenu_page('edit.php', __('Variables'), __('Variables'), CODI_VARS_ADMIN_CAP, 'vars', function() {
		//save data?
		if(isset($_POST['vars-name'])) {
			//get $_POST data
			$vars = [];
			$names = stripslashes_deep($_POST['vars-name']);
			$values = stripslashes_deep($_POST['vars-value']);
			//loop through names
			foreach($names as $index => $name) {
				$vars[$name] = $values[$index];
			}
			//save option
			update_option('codi_vars', $vars);
		} else {
			//get data
			$vars = get_option('codi_vars');
		}
		//build html
		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">Variables <small>(by <a href="https://github.com/codi-si/wp" target="_blank">codi0</a>)</small></h1>';
		echo '<p>Define information once and display it in multiple places; including post content, widgets and blocks.</p>';
		echo '<p>It allows you to update information that changes regularly in one place. You can use text, html and php in values.</p>';
		echo '<p><i>E.g., using [var name="copyright"] in a post will display <b>' . do_shortcode('[var name="copyright"]') . '</b></i></p>';
		echo '<br>';
		echo '<form method="post">';
		echo '<table id="codi-vars-list" class="wp-list-table widefat fixed striped table-view-list posts">';
		echo '<tr><th width="200"><b>Name</b></th><th><b>Value</b></th></tr>';
		foreach($vars as $name => $value) {
			echo '<tr>';
			echo '<td><input type="text" name="vars-name[]" value="' . htmlspecialchars($name, ENT_QUOTES) . '" style="width:100%;"></td>';
			echo '<td><input type="text" name="vars-value[]" value="' . htmlspecialchars($value, ENT_QUOTES) . '" style="width:100%;"></td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '<p><input type="submit" value="Save Changes" class="button button-primary"> &nbsp; <input type="button" value="Add new" id="codi-vars-add" class="button"></p>';
		echo '</form>';
		//build js
		echo '<script>';
		?>
		document.getElementById('codi-vars-add').addEventListener('click', function(e) {
			var table = document.getElementById('codi-vars-list');
			var row = document.createElement('tr');
			row.innerHTML = '<td><input type="text" name="vars-name[]" value="" style="width:100%;"></td><td><input type="text" name="vars-value[]" value="" style="width:100%;"></td>';
			table.appendChild(row);
		});
		<?php
		echo '</script>';
		echo '</div>';
	});
});