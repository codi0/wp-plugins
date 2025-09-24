<?php

/*
Plugin Name: Codi Restrict
Description: Restrict post content by user role
Version: 1.0.7
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/


//define constants
define('CODI_RESTRICT_VERSION', '1.0.7');


/* PUBLIC API */

//check whether content accessible
function codi_restrict_rules($post_id = null) {
	global $wp_roles, $post;
	//static vars
	static $_cache = [];
	//local vars
	$res = null;
	$post_id = intval($post_id) ?: ($post ? $post->ID : 0);
	//valid page?
	if($post_id > 0 && is_singular()) {
		//run check?
		if(!isset($_cache[$post_id])) {
			//set vars
			$user = wp_get_current_user();
			$current_roles = $user ? $user->roles : [];
			$rules = get_post_meta($post_id, '_codi_restrict', true) ?: null;
			//set default state
			$_cache[$post_id] = $rules;
			//process rules?
			if(!empty($rules)) {
				//anon only?
				if($rules['who'] === 'anonymous' && !is_user_logged_in()) {
					$_cache[$post_id] = null;
				}
				//members only?
				if($rules['who'] === 'members' && is_user_logged_in()) {
					$_cache[$post_id] = null;
				}
				//check roles (support both old and new format)
				if($rules['who'] === 'roles') {
					$roles_required = !empty($rules['roles']) ? $rules['roles'] : [];
					$roles_forbidden = !empty($rules['roles_forbidden']) ? $rules['roles_forbidden'] : [];
					
					if(!isset($_GET['restrict']) && codi_restrict_bypass_roles()) {
						//allow roles through
						$_cache[$post_id] = null;
					} else if (!is_user_logged_in()) {
						// Keep restriction active - user must be logged in
					} else {
						// Check required roles - user must have at least one (if any are specified)
						$has_required = empty($roles_required) || array_intersect($roles_required, $current_roles);
						
						// Check forbidden roles - user must have none of these
						$has_forbidden = !empty($roles_forbidden) && array_intersect($roles_forbidden, $current_roles);
						
						// Remove restriction if user passes both checks
						if ($has_required && !$has_forbidden) {
							$_cache[$post_id] = null;
						}
					}
				}
			}
		}
		//get value
		$res = $_cache[$post_id];
	}
	//return
	return apply_filters(__FUNCTION__, $res, $post_id);
}

//get restricted message
function codi_restrict_message() {
	global $post;
	//set default message
	if(is_user_logged_in()) {
		$message = '<p class="restricted">Please <a href="' . home_url() . '">upgrade your membership</a> to continue.</p>';
	} else {
		$message = '<p class="restricted">Please <a href="' . wp_login_url($_SERVER['REQUEST_URI']) . '">sign in</a> to continue.</p>';
	}
	//return
	return apply_filters(__FUNCTION__, $message, $post);
}

//let roles through
function codi_restrict_bypass_roles() {
	//set vars
	$user = wp_get_current_user();
	$skip = apply_filters(__FUNCTION__, [ 'administrator' ]);
	//return
	return array_intersect($user->roles, $skip);
}

function codi_restrict_custom_roles() {
	//set custom roles for this page only?
	if(isset($_GET['restrict']) && $_GET['restrict'] && codi_restrict_bypass_roles()) {
		//set vars
		$added = [];
		$user = wp_get_current_user();
		$tmp = array_map('trim', explode(',', $_GET['restrict']));
		//add temp roles
		foreach($tmp as $t) {
			if($t && !in_array($t, $user->roles)) {
				$added[] = $t;
				$user->roles[] = $t;
			}
		}
	}
}


/* FRONTEND HOOKS */

//redirect from restricted content
function codi_restrict_redirect() {
	//can access?
	if(!($rules = codi_restrict_rules())) {
		return;
	}
	//can redirect?
	if(!in_array($rules['type'], [ 'login', 'redirect' ])) {
		return;
	}
	//filter url
	$url = apply_filters(__FUNCTION__, $rules['action'] ?: wp_login_url());
	//target is login?
	if(stripos($url, 'login') !== false) {
		//add redirect?
		if(stripos($url, 'redirect') === false) {
			//update url
			$url = add_query_arg('redirect_to', rawurlencode($_SERVER['REQUEST_URI']), $url);
		}
	}
	//add $_GET?
	if($_GET && stripos($url, 'redirect') === false) {
		//set args
		$args = $_GET;
		//remove redirect?
		if(isset($args['redirect_to'])) {
			unset($args['redirect_to']);
		}
		//update url
		$url = add_query_arg(array_map('rawurlencode', $args), $url);
	}
	//redirect?
	if(!wp_doing_ajax()) {
		wp_safe_redirect($url);
		exit();
	}
}

//add message to restricted content
function codi_restrict_the_content($content) {
	//can access?
	if(!($rules = codi_restrict_rules())) {
		return $content;
	}
	//can show message?
	if($rules['type'] !== 'message') {
		return $content;
	}
	//set vars
	$newContent = array();
	$paras = explode('</p>', $content);
	//create array
	for($i=0; $i < $rules['action']; $i++) {
		if(isset($paras[$i])) {
			$newContent[] = $paras[$i];
		}
	}
	//return
	return implode('</p>', $newContent) . codi_restrict_message();
}


/* ADMIN HOOKS */

//add meta box
function codi_restrict_metabox_add() {
	//can restrict?
	if(!current_user_can('edit_others_posts')) {
		return;
	}
	//get post types
    $post_types = get_post_types(array( 'public' => true));
	//loop through post types
    foreach($post_types as $type) {
		//register meta box
        add_meta_box('codi_restrict', __('Restrict'), 'codi_restrict_metabox_content', $type, 'side');
	}
}

//create meta box content
function codi_restrict_metabox_content($post) {
	global $wp_roles;
	//set vars
	$rules = get_post_meta($post->ID, '_codi_restrict', true) ?: [];
	//css rules
	echo '<style>' . "\n";
	echo '#codi_restrict_type_wrap, #codi_restrict_action_wrap, #codi_restrict_roles_forbidden_wrap { display: none; }' . "\n";
	echo '#codi_restrict .main { display:block; font-weight:600; margin-bottom:2px; }' . "\n";
	echo '#codi_restrict [type="text"], #codi_restrict textarea { width:100%; }' . "\n";
	echo '#codi_restrict select { width:85%; }' . "\n";
	echo '#codi_restrict textarea { height: 60px; resize: vertical; }' . "\n";
	echo '</style>' . "\n";
	//add nonce field
	wp_nonce_field('codi_restrict_metabox', 'codi_restrict_metabox_nonce');
	//who can see
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_who">Who can access this page?</label>' . "\n";
	echo '<select name="codi_restrict_who" id="codi_restrict_who">' . "\n";
	foreach([ 'everyone' => 'Everyone', 'anonymous' => 'Only anonymous users', 'members' => 'Only logged in users', 'roles' => 'Only specific user roles', 'none' => 'No one' ] as $k => $v) {
		$selected = ($rules && $rules['who'] === $k) ? ' selected' : '';
		echo '<option value="' . $k .'"' . $selected . '>' . $v . '</option>' . "\n";
	}
	echo '</select>' . "\n";
	echo '</p>' . "\n";
	
	//user roles (required) - convert old checkbox format to comma-separated
	echo '<div id="codi_restrict_roles">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_roles_text">User must have these roles:</label>' . "\n";
	
	// Convert existing roles array to comma-separated string for backward compatibility
	$existing_roles = '';
	if ($rules && !empty($rules['roles']) && is_array($rules['roles'])) {
		$existing_roles = implode(', ', $rules['roles']);
	}
	
	echo '<input type="text" name="codi_restrict_roles_text" id="codi_restrict_roles_text" value="' . esc_attr($existing_roles) . '">' . "\n";
	//echo '<small>Enter role names separated by commas</small>' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	
	//forbidden roles (new feature)
	echo '<div id="codi_restrict_roles_forbidden_wrap">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_roles_forbidden_text">User must NOT have these roles:</label>' . "\n";
	
	$existing_forbidden = '';
	if ($rules && !empty($rules['roles_forbidden']) && is_array($rules['roles_forbidden'])) {
		$existing_forbidden = implode(', ', $rules['roles_forbidden']);
	}
	
	echo '<input type="text" name="codi_restrict_roles_forbidden_text" id="codi_restrict_roles_forbidden_text" value="' . esc_attr($existing_forbidden) . '">' . "\n";
	//echo '<small>Enter role names to exclude separated by commas</small>' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	
	//restrict action
	echo '<div id="codi_restrict_type_wrap">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_type">What happens to restricted users?</label>' . "\n";
	echo '<select name="codi_restrict_type" id="codi_restrict_type">' . "\n";
	foreach([ 'login' => 'Redirect to login', 'redirect' => 'Redirect to another page', 'message' => 'Display restriction message' ] as $k => $v) {
		$selected = ($rules && $rules['type'] === $k) ? ' selected' : '';
		echo '<option value="' . $k . '"' . $selected . '>' . $v . '</option>' . "\n";
	}
	echo '</select>' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	echo '<div id="codi_restrict_action_wrap">' . "\n";
	echo '<p>' . "\n";
	echo '<label class="main" for="codi_restrict_action">Enter redirect link:</label>' . "\n";
	echo '<input type="text" name="codi_restrict_action" id="codi_restrict_action" value="' . ($rules ? esc_attr($rules['action']) : '') . '">' . "\n";
	echo '</p>' . "\n";
	echo '</div>' . "\n";
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		//helper function
		var updateUi = function(trigger = null) {
			//set vars
			var whoVal = jQuery('#codi_restrict_who').val();
			var typeVal = jQuery('#codi_restrict_type').val();
			var showRoles = (whoVal === 'roles');
			var showMore = (whoVal !== 'everyone');
			var isLogin = (typeVal === 'login');
			var isRedirect = (typeVal === 'redirect');
			//display type?
			jQuery('#codi_restrict_type_wrap').css('display', showMore ? 'block' : 'none');
			//display roles?
			jQuery('#codi_restrict_roles').css('display', showRoles ? 'block' : 'none');
			jQuery('#codi_restrict_roles_forbidden_wrap').css('display', showRoles ? 'block' : 'none');
			//display action?
			jQuery('#codi_restrict_action_wrap').css('display', showMore && !isLogin ? 'block' : 'none');
			jQuery('#codi_restrict_action_wrap label').text(isRedirect ? 'Enter redirect link:' : 'Display after X paragraphs:');
			//clear action field?
			if(trigger === 'type') {
				jQuery('#codi_restrict_action').val('');
			}
		};
		//set initial
		updateUi();
		//select who
		jQuery('#codi_restrict_who').on('change', function(e) { updateUi('who') });
		jQuery('#codi_restrict_type').on('change', function(e) { updateUi('type') });
	});
	</script>
	<?php
}

//save post meta data
function codi_restrict_metabox_save($post_id) {
	global $wp_roles;
	//set vars
	$data = [];
	$types = [ 'login', 'redirect', 'message' ];
	$who = [ 'everyone', 'anonymous', 'members', 'roles', 'none' ];
	//is valid post?
	if(empty($_POST)) {
		return $post_id;
	}
	//skip autosave?
	if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { 
		return $post_id;
	}
	//valid nonce?
	if(!isset($_POST['codi_restrict_metabox_nonce']) || !wp_verify_nonce($_POST['codi_restrict_metabox_nonce'], 'codi_restrict_metabox')) {
		return $post_id;
	}
	//has permission?
	if($_POST['post_type'] === 'page') {
		if(!current_user_can('edit_page', $post_id)) {
			return $post_id;
		}
	} else {
		if(!current_user_can('edit_post', $post_id)) {
			return $post_id;
		}
	}
	//valid who?
	if(!isset($_POST['codi_restrict_who']) || !in_array($_POST['codi_restrict_who'], $who)) {
		return $post_id;
	}
	//valid type?
	if(!isset($_POST['codi_restrict_type']) || !in_array($_POST['codi_restrict_type'], $types)) {
		return $post_id;
	}
	//process data?
	if($_POST['codi_restrict_who'] !== 'everyone') {
		//set data
		$data['who'] = sanitize_text_field($_POST['codi_restrict_who']);
		$data['type'] = sanitize_text_field($_POST['codi_restrict_type']);
		$data['roles'] = [];
		$data['roles_forbidden'] = [];
		$data['action'] = '';
		
		//is url?
		if($data['type'] === 'redirect') {
			$data['action'] = esc_url_raw($_POST['codi_restrict_action']) ?: '/';
		} else if($data['type'] === 'message') {
			$data['action'] = is_numeric($_POST['codi_restrict_action']) ? intval($_POST['codi_restrict_action']) : 2;
		}
		
		//process roles (new comma-separated format)
		if($data['who'] === 'roles') {
			// Process required roles
			if(!empty($_POST['codi_restrict_roles_text'])) {
				$roles_input = sanitize_textarea_field($_POST['codi_restrict_roles_text']);
				$roles_array = array_map('trim', explode(',', $roles_input));
				$roles_array = array_filter($roles_array, function($role) use ($wp_roles) {
					return !empty($role) && $wp_roles->is_role($role);
				});
				$data['roles'] = array_values($roles_array);
			}
			
			// Process forbidden roles
			if(!empty($_POST['codi_restrict_roles_forbidden_text'])) {
				$forbidden_input = sanitize_textarea_field($_POST['codi_restrict_roles_forbidden_text']);
				$forbidden_array = array_map('trim', explode(',', $forbidden_input));
				$forbidden_array = array_filter($forbidden_array, function($role) use ($wp_roles) {
					return !empty($role) && $wp_roles->is_role($role);
				});
				$data['roles_forbidden'] = array_values($forbidden_array);
			}
		}
		
		// Handle legacy checkbox data (for backward compatibility)
		// This ensures old installs still work if someone has both formats
		if($data['who'] === 'roles' && !empty($_POST['codi_restrict_roles']) && empty($data['roles'])) {
			//loop through roles
			foreach($_POST['codi_restrict_roles'] as $k => $v) {
				//role exists?
				if(!$wp_roles->is_role($v)) {
					unset($_POST['codi_restrict_roles'][$k]);
					continue;
				}
			}
			//set roles
			$data['roles'] = $_POST['codi_restrict_roles'];
		}
	}
	//save data?
	if(!empty($data)) {
		update_post_meta($post_id, '_codi_restrict', $data);
	} else {
		delete_post_meta($post_id, '_codi_restrict');
	}
}

//hide from sitemap
function codi_restrict_sitemap_query($args) {
	//set meta?
	if(!isset($args['meta_query'])) {
		$args['meta_query'] = [];
	}
	//hide restricted posts
	$args['meta_query'][] = [
		'key' => '_codi_restrict',
		'compare' => 'NOT EXISTS',
	];
	//return
	return $args;
}

//redirect login form
function codi_restrict_login_form() {
	//is login page?
	if(isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {	
		//get action
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
		//exclude actions
		$exclude = [ '', 'rp', 'lostpassword' ];
		//is logged in?
		if(is_user_logged_in() && !wp_doing_ajax() && in_array($action, $exclude)) {
			//redirect to admin home
			wp_safe_redirect(admin_url());
			exit();
		}
	}
}


/* BLOCKS */

//enqueue block script
function codi_restrict_block_script() {
    wp_enqueue_script(
        'codi-restrict-blocks-ui', 
        plugin_dir_url(__FILE__) . 'js/blocks-ui.js', 
        ['wp-hooks', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-block-editor'], 
        CODI_RESTRICT_VERSION, 
        true
    );
}

//register post meta API call
function codi_restrict_block_meta() {
    register_post_meta('', '_codi_restrict_blocks', [
        'type'         => 'object',
        'single'       => true,
        'show_in_rest' => [
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => [
                    'type'       => 'object',
                    'properties' => [
                        'restrictionId' => ['type' => 'string'],
                        'who'           => ['type' => 'string'],
                        'roles'         => [
                            'type'  => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'roles_forbidden' => [
                            'type'  => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'type'          => ['type' => 'string'],
                        'action'        => ['type' => 'string']
                    ]
                ]
            ]
        ],
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        },
        'default' => []
    ]);
}

//check if a block should be restricted
function codi_restrict_block_denied($rule) {
    if (empty($rule) || !isset($rule['who'])) {
        return false;
    }

    $user = wp_get_current_user();
    $current_roles = $user ? $user->roles : [];

    // Anonymous only - deny if logged in
    if ($rule['who'] === 'anonymous' && is_user_logged_in()) {
        return true;
    }
    
    // Members only - deny if not logged in
    if ($rule['who'] === 'members' && !is_user_logged_in()) {
        return true;
    }
    
    // Specific roles - check both required and forbidden roles
    if ($rule['who'] === 'roles') {
        $roles_required = !empty($rule['roles']) ? $rule['roles'] : [];
        $roles_forbidden = !empty($rule['roles_forbidden']) ? $rule['roles_forbidden'] : [];

		//allow roles through?
		if(!isset($_GET['restrict']) && codi_restrict_bypass_roles()) {
			return false;
		}
        
        // User must be logged in for role-based restrictions
        if (!is_user_logged_in()) {
            return true;
        }
        
        // Check required roles - user must have at least one (if any are specified)
        $has_required = empty($roles_required) || array_intersect($roles_required, $current_roles);
        
        // Check forbidden roles - user must have none of these
        $has_forbidden = !empty($roles_forbidden) && array_intersect($roles_forbidden, $current_roles);
        
        // Deny if user doesn't have required roles OR has forbidden roles
        if (!$has_required || $has_forbidden) {
            return true;
        }
    }

    return false;
}

//message or hide for restricted blocks  
function codi_restrict_block_output($rule) {
    // Hide the block completely (return empty string)
    if ($rule['type'] === 'hide' || empty($rule['type'])) {
        return '';
    }
    
    // Show restriction message
    if ($rule['type'] === 'message') {
        $message = !empty($rule['action']) ? $rule['action'] : 'This content is restricted.';
        return '<div class="codi-restrict-block-msg" style="padding: 15px; background: #f7f7f7; border-left: 4px solid #ccc; margin: 10px 0;">' . esc_html($message) . '</div>';
    }
    
    // Default fallback - hide the block
    return '';
}

//hook into block rendering
function codi_restrict_render_block($block_content, $block) {
    // Skip if not in main query or if doing AJAX
    if (!is_main_query() || wp_doing_ajax()) {
        return $block_content;
    }

    // Skip if whole page is already restricted
    global $post;
    if ($post && codi_restrict_rules($post->ID)) {
        return $block_content; // Page-level restriction will handle it
    }

    // Get block rules
    $block_rules = get_post_meta(get_the_ID(), '_codi_restrict_blocks', true);
    if (empty($block_rules) || !is_array($block_rules)) {
        return $block_content;
    }

    // Check if this block has a restrictionId and corresponding rule
    if (!empty($block['attrs']['restrictionId'])) {
        $restriction_id = $block['attrs']['restrictionId'];
        
        // Find the rule for this block
        $rule = null;
        foreach ($block_rules as $stored_rule) {
            if (isset($stored_rule['restrictionId']) && $stored_rule['restrictionId'] === $restriction_id) {
                $rule = $stored_rule;
                break;
            }
        }
        
        // Apply restriction if rule exists and user should be denied
        if ($rule && codi_restrict_block_denied($rule)) {
            return codi_restrict_block_output($rule);
        }
    }

    return $block_content;
}

//cleanup orphaned block rules
function codi_restrict_cleanup_orphaned_rules($post_id) {
    // Skip if not a valid post
    if (!$post_id || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    
    // Skip for bulk operations, quick edits, and autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Skip if this is a REST API request (Gutenberg auto-saves)
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    
    // Only run for specific post statuses to avoid unnecessary processing
    $post_status = get_post_status($post_id);
    if (!in_array($post_status, ['publish', 'draft', 'private', 'future'])) {
        return;
    }
    
    // Get current block rules
    $block_rules = get_post_meta($post_id, '_codi_restrict_blocks', true);
    if (empty($block_rules) || !is_array($block_rules)) {
        return;
    }
    
    // Get the post content
    $post = get_post($post_id);
    if (!$post || empty($post->post_content)) {
        // If content is empty but we have rules, clean them up
        delete_post_meta($post_id, '_codi_restrict_blocks');
        return;
    }
    
    // Parse blocks from post content to find all restrictionIds
    $blocks = parse_blocks($post->post_content);
    $active_restriction_ids = [];
    
    // Recursively extract restrictionIds from blocks (improved traversal)
    $extract_restriction_ids = function($blocks) use (&$extract_restriction_ids, &$active_restriction_ids) {
        if (!is_array($blocks)) {
            return;
        }
        
        foreach ($blocks as $block) {
            // Skip if not a valid block array
            if (!is_array($block)) {
                continue;
            }
            
            // Check if this block has a restrictionId attribute
            if (isset($block['attrs']['restrictionId']) && !empty($block['attrs']['restrictionId'])) {
                $restriction_id = $block['attrs']['restrictionId'];
                // Avoid duplicates
                if (!in_array($restriction_id, $active_restriction_ids)) {
                    $active_restriction_ids[] = $restriction_id;
                }
            }
            
            // Check inner blocks (for columns, groups, etc.)
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                $extract_restriction_ids($block['innerBlocks']);
            }
        }
    };
    
    // Extract all active restriction IDs
    $extract_restriction_ids($blocks);
    
    // If no restriction IDs found in content but we have rules, clean them all
    if (empty($active_restriction_ids)) {
        delete_post_meta($post_id, '_codi_restrict_blocks');
        return;
    }
    
    // Filter out orphaned rules (rules that don't have corresponding blocks)
    $cleaned_rules = [];
    $orphaned_count = 0;
    
    foreach ($block_rules as $rule) {
        if (empty($rule['restrictionId'])) {
            // Remove rules without restrictionId
            $orphaned_count++;
            continue;
        }
        
        if (in_array($rule['restrictionId'], $active_restriction_ids)) {
            $cleaned_rules[] = $rule;
        } else {
            $orphaned_count++;
        }
    }
    
    // Only update if there were orphaned rules removed
    if ($orphaned_count > 0) {
        if (empty($cleaned_rules)) {
            // Delete meta if no rules left
            delete_post_meta($post_id, '_codi_restrict_blocks');
        } else {
            // Update with cleaned rules (already properly indexed)
            update_post_meta($post_id, '_codi_restrict_blocks', $cleaned_rules);
        }
    }
}


/* INIT */

add_action('wp', 'codi_restrict_redirect');
add_filter('the_content', 'codi_restrict_the_content', 99);
add_action('add_meta_boxes', 'codi_restrict_metabox_add');
add_action('save_post', 'codi_restrict_metabox_save');
add_action('edit_attachment', 'codi_restrict_metabox_save');
add_filter('wp_sitemaps_posts_query_args', 'codi_restrict_sitemap_query');
add_action('init', 'codi_restrict_login_form');
add_action('template_redirect', 'codi_restrict_custom_roles');

add_filter('render_block', 'codi_restrict_render_block', 10, 2);
add_action('enqueue_block_editor_assets', 'codi_restrict_block_script');
add_action('init', 'codi_restrict_block_meta');
add_action('save_post', 'codi_restrict_cleanup_orphaned_rules', 20);