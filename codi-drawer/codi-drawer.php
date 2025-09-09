<?php
/*
Plugin Name: Codi Notification Drawer
Description: Dismissible notification drawer with customisable options.
Version: 1.1
Author: codi0
Author URI: https://github.com/codi0/wp-plugins
*/

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Helpers
 */
function codi_drawer_default_options() {
    return [
        'drawer_color'   => '#005ea5',
        'header_text'    => '',
        'body_html'      => '',
        'cooldown_days'  => 30,
        'open_delay'     => 2000,      // ms
        'max_width'      => '300px',   // CSS value
        'exclude_urls'   => '',        // newline separated patterns
    ];
}

function codi_drawer_get_options() {
    $saved = get_option( 'codi-drawer', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }
    return array_merge( codi_drawer_default_options(), $saved );
}


/**
 * Sanitize settings (single option array)
 */
function codi_drawer_sanitize( $input ) {
    $defaults = codi_drawer_default_options();
    $out = [];

    // Colour (basic hex validation)
    $color = isset( $input['drawer_color'] ) ? trim( $input['drawer_color'] ) : $defaults['drawer_color'];
    if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
        $out['drawer_color'] = $color;
    } else {
        $out['drawer_color'] = $defaults['drawer_color'];
    }

    // Header text (plain text)
    $out['header_text'] = isset( $input['header_text'] )
        ? wp_kses( $input['header_text'], [] )
        : $defaults['header_text'];

    // Body HTML (allow post-like HTML)
    $out['body_html'] = isset( $input['body_html'] )
        ? wp_kses_post( $input['body_html'] )
        : $defaults['body_html'];

    // Cooldown days (int)
    $days = isset( $input['cooldown_days'] ) ? intval( $input['cooldown_days'] ) : $defaults['cooldown_days'];
    if ( $days < 1 ) $days = 1;
    if ( $days > 3650 ) $days = 3650; // cap at ~10 years
    $out['cooldown_days'] = $days;

    // Open delay (ms, non-negative)
    $delay = isset( $input['open_delay'] ) ? intval( $input['open_delay'] ) : $defaults['open_delay'];
    if ( $delay < 0 ) $delay = 0;
    $out['open_delay'] = $delay;

    // Max width (CSS value as-is, sanitized)
    $out['max_width'] = isset( $input['max_width'] )
        ? sanitize_text_field( $input['max_width'] )
        : $defaults['max_width'];

    // Exclude URLs (newline separated)
    $out['exclude_urls'] = isset( $input['exclude_urls'] ) ? trim( $input['exclude_urls'] ) : '';

    return $out;
}


/**
 * Register settings and admin page
 */
add_action( 'admin_init', function() {
    register_setting( 'codi_drawer_group', 'codi-drawer', [
        'type'              => 'array',
        'sanitize_callback' => 'codi_drawer_sanitize',
        'default'           => codi_drawer_default_options(),
        'show_in_rest'      => false,
    ] );
} );

add_action( 'admin_menu', function() {
    add_options_page( 'Notification Drawer', 'Notification Drawer', 'manage_options', 'codi-drawer', 'codi_drawer_settings_page' );
} );

function codi_drawer_settings_page() {
    $opts = codi_drawer_get_options();
    ?>
    <div class="wrap">
        <h1>Notification Drawer Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'codi_drawer_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="codi-drawer_color">Drawer colour</label></th>
                    <td>
                        <input type="color" id="codi-drawer_color" name="codi-drawer[drawer_color]" value="<?php echo esc_attr( $opts['drawer_color'] ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_header_text">Header text</label></th>
                    <td>
                        <input type="text" id="codi-drawer_header_text" name="codi-drawer[header_text]" value="<?php echo esc_attr( $opts['header_text'] ); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_body_html">Body content</label></th>
                    <td>
                        <?php
                        wp_editor(
                            $opts['body_html'],
                            'codi-drawer_body_html',
                            [
                                'textarea_name' => 'codi-drawer[body_html]',
                                'media_buttons' => false,
                                'textarea_rows' => 8,
                                'teeny'         => true,
                            ]
                        );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_cooldown_days">Cooldown days</label></th>
                    <td>
                        <input type="number" id="codi-drawer_cooldown_days" name="codi-drawer[cooldown_days]" value="<?php echo esc_attr( $opts['cooldown_days'] ); ?>" min="1" max="3650">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_open_delay">Open delay (ms)</label></th>
                    <td>
                        <input type="number" id="codi-drawer_open_delay" name="codi-drawer[open_delay]" value="<?php echo esc_attr( $opts['open_delay'] ); ?>" min="0">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_max_width">Max width (CSS)</label></th>
                    <td>
                        <input type="text" id="codi-drawer_max_width" name="codi-drawer[max_width]" value="<?php echo esc_attr( $opts['max_width'] ); ?>" class="regular-text" placeholder="e.g. 320px">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="codi-drawer_exclude_urls">Exclude URLs</label></th>
                    <td>
                        <textarea id="codi-drawer_exclude_urls" name="codi-drawer[exclude_urls]" rows="5" class="large-text code"><?php echo esc_textarea( $opts['exclude_urls'] ); ?></textarea>
                        <p class="description">One per line. If the current request URI contains any of these strings, the drawer will not be shown.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Front-end output
 */
add_action( 'wp_footer', function() {
    if ( is_admin() ) return;

    $opts = codi_drawer_get_options();

    // Excluded URLs
    if ( ! empty( $opts['exclude_urls'] ) ) {
        $current_uri = $_SERVER['REQUEST_URI'] ?? '';
        $patterns = preg_split( '/\r\n|\r|\n/', $opts['exclude_urls'] );
        foreach ( $patterns as $p ) {
            $p = trim( $p );
            if ( $p !== '' && strpos( $current_uri, $p ) !== false ) {
                return;
            }
        }
    }

    // Basic guard: if both header and body are empty, don't render the drawer UI.
    if ( $opts['header_text'] === '' || trim( wp_strip_all_tags( $opts['body_html'] ) ) === '' ) {
        return;
    }

    $color     = esc_attr( $opts['drawer_color'] );
    $header    = esc_html( $opts['header_text'] );
    $body      = $opts['body_html']; // sanitized on save
    $cooldown  = intval( $opts['cooldown_days'] );
    $delay     = intval( $opts['open_delay'] );
    $max_width = esc_attr( $opts['max_width'] );
    ?>
    <style>
    :root { --drawer-color: <?php echo $color; ?>; }
    #drawer-panel {
        position: fixed;
        left: 0;
        bottom: 0;
        width: 100%;
        background: #fff;
        border: 1px solid #ccc;
        box-shadow: 0 -2px 8px rgba(0,0,0,0.15);
        transform: translateY(100%);
        transition: transform 0.4s ease;
        font-family: sans-serif;
        z-index: 9999;
    }
    #drawer-panel.open {
        transform: translateY(0);
    }
    .drawer-header {
        background: var(--drawer-color);
        color: #fff;
        padding: 0.75rem 1rem;
        font-weight: bold;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .drawer-header button {
        background: transparent;
        border: none;
        color: #fff;
        font-size: 1.2rem;
        cursor: pointer;
    }
    .drawer-body {
        padding: 1rem;
        font-size: 0.95rem;
        line-height: 1.4;
    }
    .drawer-body .button {
        display: inline-block;
        margin-top: 0.6rem;
        padding: 0.5rem 0.75rem;
        background: var(--drawer-color);
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
    }
    .drawer-body .button:hover {
        opacity: 0.9;
    }
    @media (min-width: 400px) {
        #drawer-panel {
            max-width: <?php echo $max_width; ?>;
            left: 30px;
            border-radius: 10px 10px 0 0;
        }
        .drawer-header {
            border-radius: 10px 10px 0 0;
        }
    }
    </style>

    <div id="drawer-panel" role="dialog" aria-live="polite" aria-label="Site drawer message">
        <div class="drawer-header">
            <span><?php echo $header; ?></span>
            <button id="drawer-close" aria-label="Close">&times;</button>
        </div>
        <div class="drawer-body">
            <?php
            echo do_shortcode( $body );
            ?>
        </div>
    </div>

    <script>
    (function() {
        const drawer = document.getElementById('drawer-panel');

        const storageKey = 'drawer-dismissed';
        const sessionKey = 'drawer-seen';

        const cooldownDays = <?php echo $cooldown; ?>;
        const cooldownMs = cooldownDays * 24 * 60 * 60 * 1000;
        const openDelay = <?php echo $delay; ?>;

        const now = Date.now();
        const lastDismissed = parseInt(localStorage.getItem(storageKey), 10);
        const shownThisSession = sessionStorage.getItem(sessionKey);

        if ((isNaN(lastDismissed) || (now - lastDismissed) > cooldownMs) && !shownThisSession) {
            setTimeout(function() {
                drawer.classList.add('open');
                sessionStorage.setItem(sessionKey, '1');
            }, openDelay);
        }

        drawer.addEventListener('click', function() {
            drawer.classList.remove('open');
            localStorage.setItem(storageKey, Date.now().toString());
        });
    })();
    </script>
    <?php
});