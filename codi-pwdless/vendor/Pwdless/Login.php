<?php

namespace Pwdless;

class Login {

	public $sso;
	public $magicLink;

	protected $base_url;
	protected $redirect_url;

	public function __construct($base_url = null, $redirect_url = null) {
		$this->base_url = trim($base_url ?: get_home_url(), '/') . '/';
		$this->redirect_url = trim($redirect_url ?: admin_url(), '/') . '/';
		
		$this->sso = new Sso($this);
		$this->magicLink = new MagicLink($this);
	}

	public function get_base_url() {
		return $this->base_url;
	}

	public function get_redirect_url() {
		//check request
		$r = $_REQUEST['redirect_to'] ?? '';
		//valid redirect?
        if(!$r || !wp_validate_redirect($r)) {
			$r = $this->redirect_url;
        }
        //return
        return $r;
	}

	public function is_new_user($email, $blog_id=null) {
		//email exists?
		if(!$user = get_user_by('email', $email)) {
			return true;
		}
		//is member of current blog?
        return !is_user_member_of_blog($user->ID, $blog_id ?: get_current_blog_id());
	}

    public function login_or_register(array $identity, array $opts = []) {
    
		$newUser = false;
        $opts = array_merge([
			'source' => '',
            'roles' => '',
            'remember' => true,
        ], $opts);
        
        if(!$opts['roles']) {
			$opts['roles'] = [ 'subscriber' ];
        } else if(is_string($opts['roles'])) {
			$opts['roles'] = $opts['roles'] ? explode(',', $opts['roles']) : [];
        }
        
        foreach($opts['roles'] as $k => $v) {
			$opts['roles'][$k] = trim($v);
			if(!$opts['roles'][$k]) {
				unset($opts['roles'][$k]);
			}
        }
        
        $opts['roles'] = array_values($opts['roles']);

        // Validate email
        $email = strtolower(trim($identity['email'] ?? ''));

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'No valid email provided');
        }

        // Find or create user
        if(!$user = get_user_by('email', $email)) {
            $username = $this->generate_username($email);
            $user_id  = wp_insert_user([
                'user_login'   => $username,
                'user_email'   => $email,
                'display_name' => $identity['display_name'] ?? $username,
                'user_pass'    => wp_generate_password(24),
                'role'         => '',
            ]);
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            $user = get_user_by('id', $user_id);
            $newUser = true;
        }

        // Ensure site membership (multisite)
        $blog_id = get_current_blog_id();
        if (!is_user_member_of_blog($user->ID, $blog_id)) {
            add_user_to_blog($blog_id, $user->ID, '');
            $newUser = true;
        }
        
        // Add user roles
        if($newUser || !$user->roles) {
			$primaryRole = true;
			foreach($opts['roles'] as $role) {
				$m = $primaryRole ? 'set_role' : 'add_role';
				$user->$m($role);
				$primaryRole = false;
			}
        }
        
        //set new user flag
        $user->isNew = $newUser;

        // Login the user
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $opts['remember']);
        do_action('wp_login', $user->user_login, $user);

        return $user;
    }

    protected function generate_username($email) {
		$base = sanitize_user(explode('@', $email)[0] ?: 'user', true);
		$username = $base;
		$i = 1;
        while(username_exists($username)) {
            $username = $base . ($i++);
        }
        return $username;
    }

}