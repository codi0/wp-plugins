<?php

namespace Pwdless;

class Admin {

	protected $checkboxes = [ 'block_wp_login', 'enable_throttle' ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu() {
        add_options_page(
            'Passwordless Login',
            'Passwordless Login',
            'manage_options',
            'codi-pwdless',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('oidc_sso', 'oidc_sso', [
			'sanitize_callback' => function($input) {
				//get current data
				$existing = get_option('oidc_sso', []);
				//merge new input data
				$input = array_merge( (array) $existing, (array) $input );
				//remove old keys
				foreach($input as $k => $v) {
					if(strpos($k, 'pwdless_') === 0) {
						unset($input[$k]);
					}
				}
				//check default emails
				foreach(Email::$defaults as $defaults) {
					foreach($defaults as $k => $v) {
						if(isset($input[$k])) {
							$input[$k] = str_replace("\r\n", "\n", trim($input[$k]));
							if($input[$k] === $v) {
								unset($input[$k]);
							}
						}
					}
				}
				//return
				return $input;
			}
        ]);

        // --- General tab ---
        add_settings_section('pwdless_user', 'Users', '__return_false', 'oidc_sso_general');
        $this->field('default_roles', 'New user roles list', 'oidc_sso_general', 'pwdless_user', 'text');
        
        add_settings_section('pwdless_security', 'Security', '__return_false', 'oidc_sso_general');
        $this->field('block_wp_login', 'Block access to wp-login?', 'oidc_sso_general', 'pwdless_security', 'checkbox');
        $this->field('enable_throttle', 'Enable attempt throttling?', 'oidc_sso_general', 'pwdless_security', 'checkbox');
        $this->field('cf_turnstile_key', 'Cloudflare Turnstile Site Key', 'oidc_sso_general', 'pwdless_security', 'text');
        $this->field('cf_turnstile_secret', 'Cloudflare Turnstile Secret', 'oidc_sso_general', 'pwdless_security', 'password');

        // --- SSO tab ---
        add_settings_section('pwdless_ms', 'Microsoft', '__return_false', 'oidc_sso_sso');
		$this->field('ms_tenant', 'Tenant ID', 'oidc_sso_sso', 'pwdless_ms', 'text');
		$this->field('ms_client_id', 'Client ID', 'oidc_sso_sso', 'pwdless_ms', 'text');
        $this->field('ms_client_secret', 'Client Secret', 'oidc_sso_sso', 'pwdless_ms', 'password');

        add_settings_section('pwdless_google', 'Google', '__return_false', 'oidc_sso_sso');
        $this->field('google_client_id', 'Client ID', 'oidc_sso_sso', 'pwdless_google', 'text');
        $this->field('google_client_secret', 'Client Secret', 'oidc_sso_sso', 'pwdless_google', 'password');

		// --- Emails tab 
        add_settings_section('pwdless_email_sso_reg', 'SSO Regisration', '__return_false', 'oidc_sso_emails');
        $this->field('email_sso_reg_subject', 'Subject', 'oidc_sso_emails', 'pwdless_email_sso_reg', 'text', [ '{site_name}' ]);
        $this->field('email_sso_reg_body', 'Body', 'oidc_sso_emails', 'pwdless_email_sso_reg', 'textarea', [ '{email}', '{site_name}', '{home_link}' ]);

        add_settings_section('pwdless_email_ml_reg', 'Magic Link Registration', '__return_false', 'oidc_sso_emails');
		$this->field('email_ml_reg_subject', 'Subject', 'oidc_sso_emails', 'pwdless_email_ml_reg', 'text', [ '{site_name}' ]);
        $this->field('email_ml_reg_body', 'Body', 'oidc_sso_emails', 'pwdless_email_ml_reg', 'textarea', [ '{email}', '{site_name}', '{home_link}', '{magic_link}', '{magic_expiry}' ]);
        
        add_settings_section('pwdless_email_ml_login', 'Magic Link Login', '__return_false', 'oidc_sso_emails');
        $this->field('email_ml_login_subject', 'Subject', 'oidc_sso_emails', 'pwdless_email_ml_login', 'text', [ '{site_name}' ]);
        $this->field('email_ml_login_body', 'Body', 'oidc_sso_emails', 'pwdless_email_ml_login', 'textarea', [ '{email}', '{site_name}', '{home_link}', '{magic_link}', '{magic_expiry}' ]);        
    }

    private function field($key, $label, $page_slug, $section, $type = 'input', array $tokens = []) {
        add_settings_field($key, $label, function() use($key, $type, $section, $tokens) {
			$opts = get_option('oidc_sso', []);
			$val  = $opts[$key] ?? '';
			
			if(!$val && strpos($section, 'pwdless_email_') === 0) {
				if(isset(Email::$defaults[$section][$key])) {
					$val = Email::$defaults[$section][$key];
				}
			}

			if ($type == 'checkbox') {
				printf(
					'<input type="checkbox" name="oidc_sso[%s]" value="1"' . ($val ? ' checked' : '') .'>',
					esc_attr($key),
					esc_attr($val)
				);
			} else if ($type == 'textarea') {
				printf(
					'<textarea name="oidc_sso[%s]" rows="10" cols="50" class="large-text code">%s</textarea>',
					esc_attr($key),
					esc_textarea($val)
				);
			} else if($type == 'password') {
				printf(
					'<input type="password" name="oidc_sso[%s]" value="%s" class="regular-text">',
					esc_attr($key),
					esc_attr($val)
				);
			} else {
				printf(
					'<input type="text" name="oidc_sso[%s]" value="%s" class="regular-text">',
					esc_attr($key),
					esc_attr($val)
				);
			}

			if (!empty($tokens)) {
				echo '<p class="description">Available tokens: <code>' . implode('</code>, <code>', array_map('esc_html', $tokens)) . '</code></p>';
			}
		}, $page_slug, $section);
    }

    public function sanitize(array $input): array {
        $out = [];
        foreach ($input as $k => $v) {
            $out[$k] = is_string($v) ? sanitize_textarea_field($v) : $v;
        }
        return $out;
    }

    public function render_settings_page() {
        $active_tab = $_GET['tab'] ?? 'general';

        echo '<div class="wrap"><h1>Passwordless Settings</h1>';
        $this->render_tabs($active_tab);
        echo '<form method="post" action="options.php">';
        settings_fields('oidc_sso');

        if ($active_tab === 'general') {
            do_settings_sections('oidc_sso_general');
        } elseif ($active_tab === 'sso') {
            do_settings_sections('oidc_sso_sso');
        } elseif ($active_tab === 'emails') {
            do_settings_sections('oidc_sso_emails');
        }

        submit_button();
        echo '</form></div>';
    }

    private function render_tabs(string $active_tab) {
        $tabs = [
            'general' => 'General',
            'sso'     => 'SSO',
            'emails'  => 'Emails',
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = $active_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url(admin_url('options-general.php?page=codi-pwdless&tab=' . $slug)),
                esc_attr($class),
                esc_html($label)
            );
        }
        echo '</h2>';
    }

}