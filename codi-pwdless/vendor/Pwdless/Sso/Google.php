<?php

namespace Pwdless\Sso;

class Google extends Base {

	protected $color = '#1b8f01';

    protected function get_config() {
        $opts = get_option('oidc_sso', []);

        return [
            'client_id'      => $opts['google_client_id'] ?? '',
            'client_secret'  => $opts['google_client_secret'] ?? '',
            'discovery'      => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes'         => 'openid profile email',
            'issuer'         => 'https://accounts.google.com',
            'default_roles'   => $opts['shared_default_roles'] ?? '',
        ];
    }

}