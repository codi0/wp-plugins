<?php

/*
Plugin Name: Codi Pwdless
Description: A shortcode form that createa a single passwordless entry point for login and registration: [codi_pwdless]
Version: 1.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') or die;



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
		wp_safe_redirect($login->get_base_url());
		exit();
	}
});


/* SHORTCODE */

add_shortcode('codi_pwdless', function(array $atts = []) use ($login) {
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
    $email        = '';
    $message      = '';
    $message_type = '';
    $showForm     = true;
    
    // Has oidc error?
    if(isset($_GET['oidc_error']) && $_GET['oidc_error']) {
		$message = $_GET['oidc_error'];
		$message_type = 'error';
    }

    // Process POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log'])) {

        // 1. Email format
        if (!$message) {
			$email = sanitize_email($_POST['log']);
			if (!is_email($email)) {
				$message      = 'Please enter a valid email address';
				$message_type = 'error';
			}
		}

        // 2. Nonce check
        if (!$message && !wp_verify_nonce($_POST['_nonce'], 'codi_pwdless')) {
            $message      = 'Verification failed. Please try again.';
            $message_type = 'error';
        }

        // 3. Honeypot check
        if (!$message) {
            $honeypot = $_POST['pwd'] ?? 'empty';
            if (!empty($honeypot)) {
                $message      = 'Verification failed. Please try again.';
                $message_type = 'error';
            }
        }

        // 4. Cloudflare Turnstile
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

        // 5. Throttle check
        if (!$message && $useThrottle && ($wait = $login->magicLink->check_wait($email))) {
            if ($wait['email'] > 0) {
                $message = 'You have requested too many links for this email. Please wait <b><span class="wait-counter">' . $wait['email'] . '</span></b> seconds before trying again.';
            } else {
                $message = 'Too many requests from your network. Please try again later.';
            }
            $message_type = 'error';
        }

        // 6. Success case
        if (!$message) {
            $login->magicLink->send_link($email);
            $message      = "Please check your email. If you don't receive a login link shortly, please <a href=''>try again</a>.";
            $message_type = 'success';
            $showForm     = false;
        }
    }

    // Render
    ob_start();

    // Styles (moved from inline)
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
			
			if(timer > 0) {
				submit.disabled = true;
				var tid = setInterval(function() {
					timer--;
					counter.textContent = timer;
					if(timer <= 0) {
						clearInterval(tid);
						submit.disabled = false;
					}
				}, 1000);
			}

            form.addEventListener('submit', function(e) {
                submit.disabled = true;
            });

            window.cfReady = function(c, d) {
				var n = counter ? counter.textContent : 0;
				submit.disabled = (n > 0) || (!!d);
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