<?php

namespace Pwdless\Sso;

class Microsoft extends Base {

	protected $color = '#033c96';

    protected function get_config() {
        $opts = get_option('oidc_sso', []);

        return [
            'client_id'      => $opts['ms_client_id'] ?? '',
            'client_secret'  => $opts['ms_client_secret'] ?? '',
            'discovery'      => 'https://login.microsoftonline.com/' . rawurlencode($opts['ms_tenant'] ?? 'common') . '/v2.0/.well-known/openid-configuration',
            'scopes'         => 'openid profile email offline_access',
            'issuer'         => 'https://login.microsoftonline.com/' . ($opts['ms_tenant'] ?? 'common') . '/v2.0',
            'default_roles'   => $opts['shared_default_roles'] ?? '',
        ];
    }

}
