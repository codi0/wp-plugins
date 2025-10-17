<?php

/*
Plugin Name: Codi Pwdless
Description: A shortcode form that createa a single passwordless entry point for login and registration: [codi_pwdless]
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;


/* CONSTANTS */

define('CODI_PWDLESS_PLUGIN_DIR', __DIR__);


/* AUTOLOADER */

spl_autoload_register(function ($class) {
	$ds = DIRECTORY_SEPARATOR;
	$file = __DIR__ . $ds . 'vendor' . $ds . str_replace('\\', $ds, $class).'.php';
	if(file_exists($file)) {
		require($file);
		return true;
	}
	return false;
});


/* CLASSES */

$login = new \Pwdless\Login;
$admin = new \Pwdless\Admin;


/* BLOCK WP-LOGIN */

add_action('login_init', function() use($login) {
	if(isset($_GET['action']) && $_GET['action'] === 'logout') {
		return;
	}
	if(!$settings = get_option('oidc_sso', [])) {
		return;
	}
	if($settings['block_wp_login'] ?? false) {
		$url = $login->get_base_url();
		$r = $_GET['redirect_to'] ?? '';
		if($r && wp_validate_redirect($r)) {
			$url = add_query_arg('redirect_to', rawurlencode($r), $url);
		}
		wp_safe_redirect($url);
		exit();
	}
});


/* SHORTCODE */

add_shortcode('codi_pwdless_login', function(array $atts = []) use ($login) {
    // Settings
    $settings    = get_option('oidc_sso', []);
    $cfSiteKey   = $settings['cf_turnstile_key'] ?? '';
    $cfSecretKey = $settings['cf_turnstile_secret'] ?? '';
    $useThrottle = $settings['enable_throttle'] ?? false;

    // Attributes
    $atts = shortcode_atts([
        'header'      => "Enter your email address",
        'placeholder' => "E.g. jon.smith@acme.com",
        'submit'      => "Continue with email",
        'success'     => "Success!"
    ], $atts);

    // State
    $message = '';
    $message_type = '';
    $showForm = true;
    $email = $_POST['log'] ?? '';
    
    // Has oidc error?
    if(isset($_GET['oidc_error']) && $_GET['oidc_error']) {
		$message = $_GET['oidc_error'];
		$message_type = 'error';
    }

    // Process POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log'])) {

        // Email check
        if (!$message) {
			$validEmail = sanitize_email($email);
			if (!$validEmail || !is_email($validEmail)) {
				$message      = 'Please enter a valid email address';
				$message_type = 'error';
			}
		}

        // Nonce check
        if (!$message && !wp_verify_nonce($_POST['_nonce'], 'codi_pwdless')) {
            $message      = "Captcha verification failed. Please try again.";
            $message_type = 'error';
        }

        // Honeypot check
        if (!$message) {
            $honeypot = $_POST['pwd'] ?? 'empty';
            if (!empty($honeypot)) {
                $message      = 'Verification failed. Please try again.';
                $message_type = 'error';
            }
        }

        // Cloudflare turnstile check
        if (!$message && $cfSiteKey && $cfSecretKey) {
            $cfOk    = false;
            $cfToken = $_POST['cf-turnstile-response'] ?? '';
            if ($cfToken) {
                $cfResponse = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'body' => [
                        'secret'   => $cfSecretKey,
                        'response' => $cfToken,
                        'remoteip' => $_SERVER['REMOTE_ADDR'],
                    ],
                ]);
                if (wp_remote_retrieve_response_code($cfResponse) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($cfResponse), true);
                    $cfOk = !empty($data['success']);
                } else {
                    $cfOk = true;
                }
            }
            if (!$cfOk) {
                $message      = 'Verification failed. Please try again.';
                $message_type = 'error';
            }
        }

        // Throttle check
        if (!$message && $useThrottle && ($wait = $login->magicLink->check_wait($email))) {
            if ($wait['email'] > 0 && $wait['email'] >= $wait['ip']) {
                $message = "Too many login requests. Please wait <b><span class='wait-counter'>" . $wait['email'] . "</span></b> seconds.";
            } else {
				$message = "Too many login requests. Please try again later.";
            }
            $message_type = 'error';
        }

        // Success
        if (!$message) {
            $login->magicLink->send_link($email);
            $message      = "Please check your email and click on the login link. Don't forget to check your <b>spam folder</b>.<br><br>If you've used the wrong address or don't receive the email within a few minutes, please <a href=''>try again</a>.<br><br><b>{$email}</b>";
            $message_type = 'success';
            $showForm     = false;
        }
    }

    ob_start();

    ?>
    <style>
        .codi-pwdless input:hover,
        .codi-pwdless a:hover {
            opacity: 0.9;
        }
        .codi-pwdless [disabled] {
            opacity: 0.5 !important;
            cursor: not-allowed;
        }
        .codi-pwdless .notice {
            padding-left: 12px;
            border-left: 4px solid transparent;
        }
        .codi-pwdless .notice.success {
            border-color: green;
        }
        .codi-pwdless .notice.error {
            border-color: #d63638;
            margin-bottom: 40px;
        }
        .codi-pwdless h5 {
            text-align: center;
            margin: 40px 0;
        }
        .codi-pwdless .field.email input {
            width: 100%;
        }
        .codi-pwdless .field.submit input {
			width: 100%;
            padding: 10px;
            margin-top: 12px;
        }
        .codi-pwdless .cf-turnstile {
            margin-top: 20px;
        }
    </style>
    <?php

    // Wrap message?
    if ($message) {
        $message = '<p class="notice ' . esc_attr($message_type ?: 'error') . '">' . $message . '</p>';
    }

    // SSO Html
    $ssoHtml = $login->sso->get_html();

    // Form
    if ($showForm) {
        ?>
        <div class="codi-pwdless">
        
			<?= $message ?>
        
            <?= $ssoHtml ?>

            <form method="post">
                <?php wp_nonce_field('codi_pwdless', '_nonce'); ?>

                <?php if ($atts['header']) : ?>
                    <?php if ($ssoHtml) : ?>
                        <h5>--- Or <?= lcfirst(esc_html($atts['header'])) ?> ---</h5>
                    <?php else : ?>
                        <h4><?= esc_html($atts['header']) ?></h4>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="field email">
                    <input type="email" name="log" placeholder="<?= esc_attr($atts['placeholder']) ?>" value="<?= esc_attr($email) ?>" required>
                </div>

                <div class="field pwd" style="display:none;">
                    <input type="text" name="pwd" value="">
                </div>

                <?php if ($cfSiteKey && $cfSecretKey) : ?>
                    <div class="cf-turnstile" data-sitekey="<?= esc_attr($cfSiteKey) ?>" data-size="flexible" data-callback="cfReady"></div>
                    <script defer async src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>
                <?php endif; ?>

                <div class="field submit wp-block-button">
                    <input type="submit" name="submit" class="wp-element-button" value="<?= esc_attr($atts['submit']) ?>">
                </div>
            </form>
        </div>

        <script>
        (function() {
        
			var counter = document.querySelector('.codi-pwdless .wait-counter');
			var form = document.querySelector('.codi-pwdless form');
			var submit = form.querySelector('[type="submit"]');
			var timer = counter ? parseInt(counter.textContent) : 0;
			
			var disableSubmit = function(b) {
				submit.disabled = b;
				submit.value = b ? 'Please wait...' : 'Continue with email';
			}
			
			if(timer > 0) {
				disableSubmit(true);
				var tid = setInterval(function() {
					timer--;
					counter.textContent = timer;
					if(timer <= 0) {
						clearInterval(tid);
						disableSubmit(false);
					}
				}, 1000);
			}

            form.addEventListener('submit', function(e) {
                disableSubmit(true);
            });

            window.cfReady = function(c, d) {
				var n = counter ? counter.textContent : 0;
				var b = (n > 0) || (!!d);
				disableSubmit(b);
            }
            
            <?php if ($cfSiteKey && $cfSecretKey) : ?>
            cfReady(null, true);
            <?php endif; ?>
        
        })();
        </script>
        <?php
    } else {
		echo '<div class="codi-pwdless">' . $message . '</div>';
    }

    return ob_get_clean();
});

add_shortcode('codi_pwdless_logout', function() {
	//skip request?
	if(is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
		return;
	}
	//is logged in?
    if(is_user_logged_in()) {
        wp_logout();
    }
    //redirect user
    wp_redirect(wp_login_url());
    exit();
});

add_action('wp_footer', function() {
	global $post;
	//check embed settings
	$settings = get_option('oidc_sso', []);
	$allow_embed = $settings['allow_embed'] ?? false;
	//allow embed?
	if(!$allow_embed) {
		return;
	}
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		if(window.self === window.top) {
			return;
		}
		var wpIframe = {
			width: document.documentElement.scrollWidth,
			height: document.documentElement.scrollHeight,
			href: "<?= home_url($_SERVER['REQUEST_URI']); ?>",
			userId: <?= get_current_user_id(); ?>
		};
		window.top.postMessage({ wpIframe: wpIframe }, '*');
	});
	</script>
	<?php
	//is embed request?
	if(!isset($_GET['embed'])) {
		return;
	}
	//has login form?
	if(!$post || !has_shortcode($post->post_content, 'codi_pwdless_login')) {
		return;
	}
	?>
	<style>
	html { height: auto; min-height: auto; }
	body { height: auto; min-height: auto; }
	body > .wp-site-blocks { height: auto; min-height: auto; margin-top: 0; margin-bottom: 0; }
	body > .entry-content, body > .wp-site-blocks > .entry-content { height: auto; min-height: auto; margin-top: 0; margin-bottom: 0; }
	body > header, body > .wp-site-blocks > header { display: none; }
	body > footer, body > .wp-site-blocks > footer { display: none; }
	</style>
	<?php
}, 999);

//restrict iframe usage
add_action('send_headers', function() {
	//check embed settings
	$settings = get_option('oidc_sso', []);
	$embed_domains = $settings['embed_domains'] ?? [];
	//set csp header?
	if($embed_domains) {
		$embed_domains = str_replace("\r\n", "\n", $embed_domains);
		$embed_domains = str_replace("\n", " ", $embed_domains);
		header("Content-Security-Policy: frame-ancestors 'self' " . $embed_domains . ";");
	}
});

//overwrite set auth cookie
if(!function_exists('wp_set_auth_cookie')) {
	function wp_set_auth_cookie( $user_id, $remember = false, $secure = '', $token = '' ) {
		if ( $remember ) {
			$expiration = time() + apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $user_id, $remember );
			$expire = $expiration + ( 12 * HOUR_IN_SECONDS );
		} else {
			$expiration = time() + apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, $remember );
			$expire     = 0;
		}

		if ( '' === $secure ) {
			$secure = is_ssl();
		}

		$secure_logged_in_cookie = $secure && 'https' === parse_url( get_option( 'home' ), PHP_URL_SCHEME );
		$secure = apply_filters( 'secure_auth_cookie', $secure, $user_id );
		$secure_logged_in_cookie = apply_filters( 'secure_logged_in_cookie', $secure_logged_in_cookie, $user_id, $secure );

		if ( $secure ) {
			$auth_cookie_name = SECURE_AUTH_COOKIE;
			$scheme           = 'secure_auth';
		} else {
			$auth_cookie_name = AUTH_COOKIE;
			$scheme           = 'auth';
		}

		if ( '' === $token ) {
			$manager = WP_Session_Tokens::get_instance( $user_id );
			$token   = $manager->create( $expiration );
		}

		$auth_cookie      = wp_generate_auth_cookie( $user_id, $expiration, $scheme, $token );
		$logged_in_cookie = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

		do_action( 'set_auth_cookie', $auth_cookie, $expire, $expiration, $user_id, $scheme, $token );
		do_action( 'set_logged_in_cookie', $logged_in_cookie, $expire, $expiration, $user_id, 'logged_in', $token );

		if ( ! apply_filters( 'send_auth_cookies', true, $expire, $expiration, $user_id, $scheme, $token ) ) {
			return;
		}
		
		//FUNCTION UPDATED BELOW THIS POINT

		//don't change admin cookie
		setcookie( $auth_cookie_name, $auth_cookie, $expire, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, $secure, true );
		
		//check embed settings
		$settings = get_option('oidc_sso', []);
		$allow_embed = $settings['allow_embed'] ?? false;
		
		//set same site attribute
		$sameSite = $allow_embed ? 'None' : '';

		//set cookies with same site attribute
		setcookie( $auth_cookie_name, $auth_cookie, [ "expires" => $expire, "path" => PLUGINS_COOKIE_PATH, "domain" => COOKIE_DOMAIN, "secure" => $secure, "httponly" => true, "SameSite" => $sameSite ] );
		setcookie( LOGGED_IN_COOKIE, $logged_in_cookie, [ "expires" => $expire, "path" => COOKIEPATH, "domain" => COOKIE_DOMAIN, "secure" => $secure_logged_in_cookie, "httponly" => true, "SameSite" => $sameSite ] );
		if ( COOKIEPATH !== SITECOOKIEPATH ) {
			setcookie( LOGGED_IN_COOKIE, $logged_in_cookie, [ "expires" => $expire, "path" => SITECOOKIEPATH, "domain" => COOKIE_DOMAIN, "secure" => $secure_logged_in_cookie, "httponly" => true, "SameSite" => $sameSite ] );
		}
	}
}