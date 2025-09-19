<?php

namespace Pwdless\Sso;

use Pwdless\Login;
use Pwdless\Email;

abstract class Base {

    protected $id;
	protected $label;
	protected $color;
	protected $orchestrator;

    public function __construct(Login $orchestrator, $id=null, $label=null) {
		$this->orchestrator = $orchestrator;
        $this->id = strtolower($id ?: (new \ReflectionClass($this))->getShortName());
        $this->label = $label ?: 'Continue with ' . ucfirst($this->id);
    }

    abstract protected function get_config();

	public function get_id() {
		return $this->id;
	}

	public function get_label() {
		return $this->label;
	}

	public function get_color() {
		return $this->color;
	}

	public function is_active() {
		$config = $this->get_config();
		return ($config['client_id'] ?? '') && ($config['client_secret'] ?? '');
	}

    /** ----- FLOW ENTRY POINTS ----- **/
    public function start_auth() {
        $cfg = $this->get_config();
        if (empty($cfg['client_id'])) {
			$this->error_redirect('Provider not configured');
		}
        if(!$oidc = $this->discover($cfg['discovery'])) {
			$this->error_redirect('OIDC discovery failed');
		}
        $state = $this->random_b64url(32);
        $nonce = $this->random_b64url(32);
        $code_verifier = $this->random_b64url(64);
        $code_challenge = $this->b64url_encode(hash('sha256', $code_verifier, true));

        set_transient("oidc_state_$state", [
            'provider' => $this->id,
            'nonce' => $nonce,
            'code_verifier' => $code_verifier,
            'redirect_to' => $this->orchestrator->get_redirect_url(),
        ], 600);

        $auth_url = add_query_arg([
            'client_id' => $cfg['client_id'],
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => $cfg['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            'redirect_uri' => rawurlencode($this->get_callback_url()),
        ], $oidc['authorization_endpoint']);

        wp_redirect($auth_url);
        exit;
    }

    public function handle_callback() {
        $cfg   = $this->get_config();
        $code  = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $err   = $_GET['error'] ?? '';

        if ($err) {
			$this->error_redirect("Provider error: ".sanitize_text_field($err));
		}

        $st = get_transient("oidc_state_$state");
        if (!$code || !$st) {
			$this->error_redirect('Invalid or expired state');
		}
        delete_transient("oidc_state_$state");

        if(!$oidc = $this->discover($cfg['discovery'])) {
			$this->error_redirect('OIDC discovery failed');
		}

        $body = [
            'client_id' => $cfg['client_id'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'code_verifier' => $st['code_verifier'],
            'redirect_uri' => $this->get_callback_url(),
        ];

        if (!empty($cfg['client_secret'])) {
            $body['client_secret'] = $cfg['client_secret'];
        }

        $resp = wp_remote_post($oidc['token_endpoint'], [
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'timeout' => 15,
            'body'    => $body
        ]);

        if (is_wp_error($resp)) {
			$this->error_redirect('Token request failed');
		}

        $data     = json_decode(wp_remote_retrieve_body($resp), true);
        $id_token = $data['id_token'] ?? '';
        
        if(!$claims = $this->validate_id_token($id_token, $oidc, $cfg['issuer'], $cfg['client_id'], $st['nonce'])) {
			$this->error_redirect('Invalid ID token');
		}

        $this->map_user($claims, $cfg);
        wp_safe_redirect($st['redirect_to'] ?: $this->orchestrator->get_redirect_url());
        exit;
    }

	protected function get_callback_url() {
		return add_query_arg([
			'action'   => 'oidc_callback',
			'provider' => $this->id
		], $this->orchestrator->get_base_url());
	}

    protected function discover($url) {
        $key = 'oidc_meta_'.md5($url);
        $c   = get_transient($key);
        if ($c) return $c;
        $r = wp_remote_get($url, ['timeout'=>10]);
        if (is_wp_error($r)) return null;
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($j) || empty($j['authorization_endpoint']) || empty($j['token_endpoint']) || empty($j['jwks_uri']) || empty($j['issuer'])) return null;
        set_transient($key, $j, DAY_IN_SECONDS);
        return $j;
    }

    protected function get_jwks($url) {
        $key = 'oidc_jwks_'.md5($url);
        $c   = get_transient($key);
        if ($c) return $c;
        $r = wp_remote_get($url, ['timeout'=>10]);
        if (is_wp_error($r)) return null;
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($j) || empty($j['keys'])) return null;
        set_transient($key, $j, DAY_IN_SECONDS);
        return $j;
    }

    protected function validate_id_token($jwt, $oidc, $issuer, $client_id, $expected_nonce) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h64,$p64,$s64] = $parts;
        $header = json_decode($this->b64url_decode($h64), true);
        $claims = json_decode($this->b64url_decode($p64), true);
        $sig    = $this->b64url_decode($s64);

        if (($header['alg'] ?? '') !== 'RS256') return null;

        $jwks = $this->get_jwks($oidc['jwks_uri']);
        if (!$jwks) return null;
        $kid = $header['kid'] ?? '';
        $pem = null;
        foreach ($jwks['keys'] as $jwk) {
            if (($jwk['kid'] ?? '') === $kid) {
                if (!empty($jwk['x5c'][0])) {
                    $pem = $this->x5c_to_pem($jwk['x5c'][0]);
                } elseif (!empty($jwk['n']) && !empty($jwk['e'])) {
                    $pem = $this->jwk_to_pem($jwk['n'], $jwk['e']);
                }
                break;
            }
        }
        if (!$pem || openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;

        // Claim checks
        $now = time();
        if (($claims['iss'] ?? '') !== $issuer) return null;
        if (($claims['aud'] ?? '') !== $client_id) return null;
        if ($expected_nonce && ($claims['nonce'] ?? '') !== $expected_nonce) return null;
        if (($claims['exp'] ?? 0) <= $now || ($claims['nbf'] ?? 0) > $now) return null;

        return $claims;
    }

	protected function map_user($claims, $cfg) {
		$identity = [
			'email'        => $claims['email'] ?? $claims['preferred_username'] ?? '',
			'display_name' => $claims['name'] ?? '',
			'meta'         => $claims,
		];

		$user = $this->orchestrator->login_or_register($identity, [
			'roles' => $cfg['default_roles'] ?? 'subscriber',
		]);

		if (is_wp_error($user)) {
			$this->error_redirect($user->get_error_message());
		}
		
		if($user->isNew) {
			$type = 'pwdless_email_sso_reg';
			(new Email)->send($type, $user->user_email);	
		}
	}

    protected function b64url_encode($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    protected function b64url_decode($b64u) {
        $b64 = strtr($b64u, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        return base64_decode($b64);
    }

    protected function random_b64url($bytes) {
        return $this->b64url_encode(random_bytes($bytes));
    }

    protected function x5c_to_pem($x5c_first) {
        $pem  = "-----BEGIN CERTIFICATE-----\n".
                trim(chunk_split($x5c_first, 64, "\n")).
                "\n-----END CERTIFICATE-----\n";
        $cert = openssl_x509_read($pem);
        $pubKey = openssl_pkey_get_public($cert);
        $details = openssl_pkey_get_details($pubKey);
        return $details && !empty($details['key']) ? $details['key'] : null;
    }

    protected function jwk_to_pem($n_b64u, $e_b64u) {
        $n = $this->b64url_decode($n_b64u);
        $e = $this->b64url_decode($e_b64u);
        $modulus = $this->asn1_encode_integer($n);
        $exponent = $this->asn1_encode_integer($e);
        $seq = $this->asn1_encode_sequence($modulus.$exponent);
        $bitstring = "\x03". $this->asn1_length(strlen("\x00".$seq))."\x00".$seq;
        $algo = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00"; // rsaEncryption
        $spki = $this->asn1_encode_sequence($algo.$bitstring);
        return "-----BEGIN PUBLIC KEY-----\n".
               chunk_split(base64_encode($spki), 64, "\n").
               "-----END PUBLIC KEY-----\n";
    }

    protected function asn1_encode_integer($x) {
        $x = ltrim($x, "\x00");
        if (strlen($x) && (ord($x[0]) & 0x80)) $x = "\x00".$x;
        return "\x02".$this->asn1_length(strlen($x)).$x;
    }

    protected function asn1_encode_sequence($x) {
        return "\x30".$this->asn1_length(strlen($x)).$x;
    }

    protected function asn1_length($len) {
        if ($len < 128) return chr($len);
        $out = '';
        while ($len > 0) {
            $out = chr($len & 0xFF).$out;
            $len >>= 8;
        }
        return chr(0x80 | strlen($out)).$out;
    }

    protected function error_redirect($msg) {
        wp_safe_redirect(add_query_arg('oidc_error', rawurlencode($msg), $this->orchestrator->get_base_url()));
        exit;
    }

}