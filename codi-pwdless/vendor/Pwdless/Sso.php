<?php

namespace Pwdless;

class Sso {

    const NONCE_ACTION = 'oidc_sso_start';

	protected $providers = [];
	protected $orchestrator;

    public function __construct(Login $orchestrator) {
		$this->orchestrator = $orchestrator;
        $this->providers = [
            'microsoft365' => new Sso\Microsoft365($orchestrator),
            'google' => new Sso\Google($orchestrator),
        ];
        add_action('init', [ $this, 'route' ]);
    }

    public function route() {
        $action   = $_REQUEST['action']   ?? '';
        $provider = $_REQUEST['provider'] ?? '';
        if (!isset($this->providers[$provider])) {
			return;
		}
        if ($action === 'oidc_start') {
            check_admin_referer(self::NONCE_ACTION);
            $this->providers[$provider]->start_auth();
            exit;
        }
        if ($action === 'oidc_callback') {
            $this->providers[$provider]->handle_callback();
            exit;
        }
    }

    public function get_html() {
		$out = '';
        foreach($this->providers as $provider) {
			if(!$provider->is_active()) {
				continue;
			}

            $args = [
                'action'   => 'oidc_start',
                'provider' => $provider->get_id(),
                '_wpnonce' => wp_create_nonce(self::NONCE_ACTION)
            ];

			$id = $provider->get_id();
			$label = $provider->get_label();
			$color = $provider->get_color();
            $url = add_query_arg($args, $this->orchestrator->get_base_url());

            $out .= '<a class="provider ' . esc_attr($id) . '" href="' . esc_url($url) . '" style="display:block; padding:10px; border:1px solid #0a0a0a; border-radius:10px; color:#fff; background:' . $color . '; margin-bottom:15px; text-align:center; text-decoration:none;">' . esc_html($label) . '</a>';
        }
        if($out) {
			$out = '<div class="sso-buttons">' . $out . '</div>';
        }
        return $out;
    }

}