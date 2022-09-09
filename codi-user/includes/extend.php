<?php

//extend user form
function codi_user_extend($name, $opts=null) {
	//set cache
	static $cache = [];
	//set field?
	if($name && is_array($opts)) {
		//format opts
		$opts = array_merge([
			'label' => '',
			'render' => null,
			'validate' => null,
			'save' => null,
			'forms' => [],
			'admin' => null,
			'position' => 10,
		], $opts);
		//add to cache
		$cache[$name] = apply_filters(__FUNCTION__, $opts, $name);
		//sort by position
		uasort($cache, function($a, $b) {
			if($a['position'] == $b['position']) {
				return 0;
			} else {
				return ($a['position'] < $b['position']) ? -1 : 1;
			}
		});
		//return
		return true;
	}
	//return
	return isset($cache[$name]) ? $cache[$name] : $cache;
}

//get user form cache
function codi_user_extend_cache($form, $user = null) {
	//set cache
	static $cache = [];
	//valid form?
	if(!in_array($form, [ 'login', 'password', 'register', 'profile' ])) {
		return [];
	}
	//get cache?
	if(isset($cache[$form])) {
		return $cache[$form];
	}
	//set array
	$cache[$form] = [];
	//get user
	$user = codi_user_form_user($user);
	//loop through fields
	foreach(codi_user_extend(null) as $name => $meta) {
		//form match?
		if(!in_array($form, $meta['forms'])) {
			continue;
		}
		//admin match?
		if(!is_null($meta['admin']) && $meta['admin'] != is_admin()) {
			continue;
		}
		//is callback?
		if(is_callable($meta['render'])) {
			//get field value
			$value = codi_user_form_input($name, $user);
			//execute callback
			ob_start();
			$t1 = call_user_func($meta['render'], $name, $value, $user);
			$t2 = ob_get_clean();
			//update input
			if(is_string($t1)) {
				$meta['render'] = $t1;
			} else if(is_string($t2)) {
				$meta['render'] = $t2;
			} else {
				$meta['render'] = '';
			}
			//check placeholders
			foreach([ 'name', 'value' ] as $p) {
				//can replace?
				if(!$$p || is_scalar($$p)) {
					$meta['render'] = str_replace('src="{' . $p . '}"', 'src="' . esc_url($$p) . '"', $meta['render']);
					$meta['render'] = str_replace('href="{' . $p . '}"', 'href="' . esc_url($$p) . '"', $meta['render']);
					$meta['render'] = str_replace('"{' . $p . '}"', '"' . esc_attr($$p) . '"', $meta['render']);
					$meta['render'] = str_replace('{' . $p . '}', esc_html($$p), $meta['render']);
				}
			}
		}
		//valid input?
		if(!$name || !$meta['render']) {
			continue;
		}
		//add field
		$cache[$form][$name] = $meta;
	}
	//filter results
	$cache[$form] = apply_filters(__FUNCTION__, $cache[$form]);
	//return
	return $cache[$form];
}

//render form field
function codi_user_extend_field($name, $label, $field) {
	//set vars
	$html = '';
	//is admin?
	if(is_admin()) {
		$html .= '<table id="' . $name . '_field" class="field form-table">' . "\n";
		$html .= '<tr>' . "\n";
		$html .= '<th>' . "\n";
		if($label) {
			$html .= '<label for="' . $name . '">' . esc_html__($label) . '</label>' . "\n";
		}
		$html .= '</th>' . "\n";
		$html .= '<td>' . "\n";
		$html .= $field . "\n";
		$html .= '</td>' . "\n";
		$html .= '</tr>' . "\n";
		$html .= '</table>' . "\n";
	} else {
		$html .= '<p id="' . $name . '_field" class="field">' . "\n";
		if($label) {
			$html .= '<label for="' . $name . '">' . esc_html__($label) . '</label>' . "\n";
		}
		$html .= $field . "\n";
		$html .= '</p>' . "\n";
	}
	//return
	return apply_filters(__FUNCTION__, $html, $name);
}

//render hook
function codi_user_extend_render($user = null) {
	//set vars
	$html = '';
	$form = '';
	$current = current_action();
	//select form
	if($current === 'user_new_form' || $current === 'register_form') {
		$form = 'register';
	} else if($current === 'show_user_profile' || $current === 'edit_user_profile' || $current === 'profile_form') {
		$form = 'profile';
	} else if($current === 'login_form') {
		$form = 'login';
	} else if($current === 'forgottenpassword_form') {
		$form = 'password';
	}
	//stop here?
	if(!$form) return;
	//add hidden input
	$html .= '<input type="hidden" name="codi_user_form" value="' . $form . '">';
	//loop through fields
	foreach(codi_user_extend_cache($form, $user) as $name => $meta) {
		$html .= codi_user_extend_field($name, $meta['label'], $meta['render']);
	}
	//render
	echo apply_filters(__FUNCTION__, $html, $user);
}

//validate hook
function codi_user_extend_validate($errors, $update = false, $user = null) {
	//set vars
	$user = is_object($user) ? $user : null;
	$form = isset($_POST['codi_user_form']) ? $_POST['codi_user_form'] : '';
	//is object?
	if(!is_object($errors)) {
		return $errors;
	}
	//cache found?
	if(!$cache = codi_user_extend_cache($form, $user)) {
		return $errors;
	}
	//loop through fields
	foreach($cache as $name => $meta) {
		//validate field?
		if(is_callable($meta['validate'])) {
			//get field value
			$value = codi_user_form_input($name);
			//execute callback
			$res = call_user_func($meta['validate'], $name, $value, $user, $errors);
			//is error class?
			if(is_wp_error($res)) {
				$errors = $res;
			}
		}
	}
	//return
	return apply_filters(__FUNCTION__, $errors);
}

//save hook
function codi_user_extend_save($user_id) {
	//run once?
	static $run = false;
	if($run) return;
	//set vars
	$data = [];
	$run = true;
	$user = get_user_by('id', $user_id);
	$form = isset($_POST['codi_user_form']) ? $_POST['codi_user_form'] : '';
	//cache found?
	if(!$cache = codi_user_extend_cache($form, $user)) {
		return;
	}
	//loop through fields
	foreach($cache as $name => $meta) {
		//get field value
		$value = codi_user_form_input($name);
		//save field
		if(is_callable($meta['save'])) {
			call_user_func($meta['save'], $name, $value, $user);
		} else if(!property_exists($user, $name)) {
			update_user_meta($user->ID, $name, $value);
		}
	}
	//return
	return apply_filters(__FUNCTION__, $user);
}

//add hooks
if(is_admin()) {
	//render
	add_action('user_new_form', 'codi_user_extend_render');
	add_action('show_user_profile', 'codi_user_extend_render');
	add_action('edit_user_profile', 'codi_user_extend_render');
	//validate
	add_action('user_profile_update_errors', 'codi_user_extend_validate', 10, 3);
	//save
	add_action('user_register', 'codi_user_extend_save');
	add_action('profile_update', 'codi_user_extend_save');
} else {
	//render
	add_action('login_form', 'codi_user_extend_render');
	add_action('lostpassword_form', 'codi_user_extend_render');
	add_action('register_form', 'codi_user_extend_render');
	add_action('profile_form', 'codi_user_extend_render', 10, 2);
	//validate
	add_filter('login_errors', 'codi_user_extend_validate', 99, 1);
	add_filter('lostpassword_errors', 'codi_user_extend_validate', 99, 2);
	add_filter('registration_errors', 'codi_user_extend_validate', 99, 3);
	add_action('profile_errors', 'codi_user_extend_validate', 99, 3);
	//save
	add_action('user_register', 'codi_user_extend_save');
	add_action('profile_update', 'codi_user_extend_save');
}