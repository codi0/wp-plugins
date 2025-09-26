<?php

/**
 * Plugin Name: Codi Plugin Optimizer
 * Description: Tools to optimize WordPress plugin usage.
 * Version: 0.1.0
 * Author: codi0
 */


/* CONFIG */

// Profiler data
class CPO {

	public static $logPath = WP_CONTENT_DIR . '/cpo-options-profiler.json';
	
	public static $settingsName = 'cpo_settings';

	public static $logData = [
		'autoloaded'      => [],
		'not_autoloaded'  => [],
	];

	public static $settingsDefaults = [
		'enabled' => 1,
		'test_mode' => 1,
		'autoload_threshold' => 100,
        'noautoload_threshold' => 0,
        'size_upper_threshold' => 100,
        'size_lower_threshold' => 1,
    ];

}


/* HELPERS */

function cpo_load_settings() {
	//set defaults
	$defaults = CPO::$settingsDefaults;
    //get saved options
    $saved = get_option(CPO::$settingsName, []);
    //return
    return array_merge($defaults, $saved);
}

function cpo_save_settings(array $settings) {
	//set defaults
	$defaults = CPO::$settingsDefaults;
	//merge defaults
	$settings = array_merge($defaults, $settings);
	//update
	update_option(CPO::$settingsName, $settings, false);
}

function cpo_load_log() {
	//set defaults
	$defaults = [
		'autoloaded' => [],
		'not_autoloaded' => [],
	];
	//load file?
	if(is_file(CPO::$logPath)) {
		$data = file_get_contents(CPO::$logPath);
		$data = $data ? json_decode($data, true) : [];
	}
	//return
	return array_merge($defaults, $data ?? []);
}

function cpo_save_log(array $data) {
	file_put_contents(CPO::$logPath, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}


/* TRACKING */

// Track autoloaded options
add_filter('alloptions', function($opts) {
    if(!CPO::$logData['autoloaded']) {
		foreach($opts as $k => $v) {
			if(strpos($k, '_transient') === false) {
				$bytes = strlen(maybe_serialize($v));
				CPO::$logData['autoloaded'][$k] = [
					'count' => 0,
					'size' => round($bytes / 1024, 1),
				];
			}
		}
	}
	return $opts;
});

// Track non-autoloaded options
add_filter('pre_option', function($pre, $option, $default_value) {
    if(CPO::$logData['autoloaded'] && strpos($option, '_transient') === false) {
		if ( isset(CPO::$logData['autoloaded'][$option]) ) {
			CPO::$logData['autoloaded'][$option]['count'] = 1;
		} else if( !isset(CPO::$logData['not_autoloaded'][$option]) ) {
			CPO::$logData['not_autoloaded'][$option] = [
				'count' => 1,
				'default' => $default_value,
				'size' => 0,
			];
			add_filter("option_{$option}", function($value, $option) {
				$bytes = strlen(maybe_serialize($value));
				CPO::$logData['not_autoloaded'][$option]['size'] = round($bytes / 1024, 1);
				return $value;
			}, 10, 2);
		}
	}
    return $pre;
}, 10, 3);

// Save profiling data
add_action('wp_footer', function() {
	// Don't save if...
    if ( current_user_can('manage_options') ) {
	//	return;
    }

	// Get current page slug
    $slug = '/' . trim(str_replace(get_home_url(), '', get_permalink()), '/');

	// Get current log data
    $log = cpo_load_log();

    // Merge autoloaded usage counts
    foreach ( CPO::$logData['autoloaded'] as $opt => $meta ) {
		$log['autoloaded'][$slug][$opt] = $meta;
    }

    // Merge non-autoloaded usage counts
    foreach ( CPO::$logData['not_autoloaded'] as $opt => $meta ) {
		$log['not_autoloaded'][$slug][$opt] = $meta;
    }

    // Save log
    cpo_save_log($log);
}, 99999);


/* ADMIN */

add_action('admin_menu', function() {
    add_options_page(
        'Plugin Optimizer',
        'Plugin Optimizer',
        'manage_options',
        'cpo-admin',
        'cpo_admin_page',
    );
});

function cpo_admin_page() {
	// Set defaults
	$report = [];
	$settings = [];

    // Save settings
    if ( isset( $_POST['cpo_save_settings'] ) && check_admin_referer( 'cpo_save_settings' ) ) {
        $settings = [
            'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
            'test_mode' => isset( $_POST['test_mode'] ) ? 1 : 0,
            'size_upper_threshold' => (int) $_POST['size_upper_threshold'],
            'size_lower_threshold' => (int) $_POST['size_lower_threshold'],
            'autoload_threshold' => max( 0, min( 100, (int) $_POST['autoload_threshold'] ) ),
            'noautoload_threshold' => max( 0, min( 100, (int) $_POST['noautoload_threshold'] ) ),
        ];
        cpo_save_settings($settings);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Update autoload flags
    if ( isset( $_POST['cpo_update_autoloading'] ) && check_admin_referer( 'cpo_update_autoloading' ) ) {
        $report = cpo_run_autoload_update();
    }

    // Clear log
    if ( isset( $_POST['cpo_clear_log'] ) && check_admin_referer( 'cpo_clear_log' ) ) {
		cpo_save_log([]);
    }

    $settings = cpo_load_settings();
    ?>
    <div class="wrap">
        <h1>Plugin Optimizer</h1>

        <form method="post">
            <?php wp_nonce_field( 'cpo_save_settings' ); ?>
            <h2>Settings</h2>
            <p>
                <label>
                    <input type="checkbox" name="enabled" <?php checked( $settings['enabled'] ); ?>>
                    Enable profiling on frontend traffic?
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="test_mode" <?php checked( $settings['test_mode'] ); ?>>
                    Use test mode to skip database updates when using "update autoloading"?
                </label>
            </p>
            <p>
                <label style="display:inline-block; width:250px;">Autoload if hit frequency above: </label>
                <input type="number" name="autoload_threshold" min="0" max="100" value="<?php echo esc_attr( $settings['autoload_threshold'] ); ?>" /> %
            </p>
            <p>
                <label style="display:inline-block; width:250px;">Don't autoload if hit frequency below: </label>
                <input type="number" name="noautoload_threshold" min="0" max="100" value="<?php echo esc_attr( $settings['noautoload_threshold'] ); ?>" /> %
            </p>
            <p>
                <label style="display:inline-block; width:250px;">Never autoload if size above: </label>
                <input type="number" name="size_upper_threshold" min="0" max="999" value="<?php echo esc_attr( $settings['size_upper_threshold'] ); ?>" /> kb
            </p>
            <p>
                <label style="display:inline-block; width:250px;">Always autoload if size below:  </label>
                <input type="number" name="size_lower_threshold" min="0" max="999" value="<?php echo esc_attr( $settings['size_lower_threshold'] ); ?>" /> kb
            </p>
            <p><input type="submit" name="cpo_save_settings" class="button button-primary" value="Save settings"></p>
        </form>
        
        <h2 style="margin-top:30px;">Actions</h2>
        
        <?php
        if ($report) {
			echo '<div class="updated"><p>' . esc_html( $report['summary'] ) . '</p></div>';
			if ( ! empty( $report['changes'] ) ) {
				echo '<details style="margin:8px 0;"><summary>View changes</summary>';
				echo '<table class="widefat striped" style="margin-top:10px;"><thead><tr><th>Option</th><th>From</th><th>To</th><th>Reason</th></tr></thead><tbody>';
				foreach ( $report['changes'] as $row ) {
					echo '<tr><td>' . esc_html( $row['option'] ) . '</td><td>' . esc_html( $row['from'] ) . '</td><td>' . esc_html( $row['to'] ) . '</td><td>' . esc_html( $row['reason'] ) . '</td></tr>';
				}
				echo '</tbody></table></details>';
			}
			if ( ! empty( $report['notes'] ) ) {
				echo '<p style="color:#666;">' . esc_html( $report['notes'] ) . '</p>';
			}        
        }
        ?>

        <form method="post" style="margin-top:20px; display:inline-block;">
            <?php wp_nonce_field( 'cpo_update_autoloading' ); ?>
            <input type="submit" name="cpo_update_autoloading" class="button" value="Update autoloading">
        </form>
        
        &nbsp;&nbsp;

        <form method="post" style="margin-top:10px; display:inline-block;">
            <?php wp_nonce_field( 'cpo_clear_log' ); ?>
            <input type="submit" name="cpo_clear_log" class="button" value="Clear log">
        </form>

        <br><br>

        <?php cpo_render_log_table(); ?>
    </div>
    <?php
}

function cpo_compute_stats( $log ) {
    // Gather unique slugs
    $slugs = [];
    foreach ( [ 'autoloaded', 'not_autoloaded' ] as $key ) {
        foreach ( $log[ $key ] as $slug => $opts ) {
            $slugs[ $slug ] = true;
        }
    }
    $slug_list = array_keys( $slugs );
    sort( $slug_list );
    $total_slugs = count( $slug_list );

    // Totals across slugs
    $totals = [
        'autoloaded' => [],
        'not_autoloaded' => [],
    ];
    foreach ( [ 'autoloaded', 'not_autoloaded' ] as $key ) {
        foreach ( $log[ $key ] as $slug => $opts ) {
            foreach ( $opts as $opt => $meta ) {
                if ( ! isset( $totals[ $key ][ $opt ] ) ) {
					$totals[ $key ][ $opt ] = [ 'count' => 0 ];
				}
				if ( array_key_exists('default', $meta) ) {
					$totals[ $key ][ $opt ]['default'] = $meta['default'];
				}
				if ( array_key_exists('size', $meta) ) {
					$totals[ $key ][ $opt ]['size'] = $meta['size'];
				}
                $totals[ $key ][ $opt ]['count'] += (int) $meta['count'];
            }
        }
    }

    return [
        'slugs'       => $slug_list,
        'total_slugs' => $total_slugs,
        'totals'      => $totals,
    ];
}

function cpo_render_log_table() {
    $log   = cpo_load_log();
    $stats = cpo_compute_stats( $log );

    if ( $stats['total_slugs'] === 0 ) {
        echo '<p>No profiler data found.</p>';
        return;
    }

    echo '<h3>Page slugs visited</h3><ul>';
    foreach ( $stats['slugs'] as $slug ) {
        echo '<li>' . esc_html( $slug ) . '</li>';
    }
    echo '</ul>';

    // Non-autoloaded options ranked by highest uses
    $na = $stats['totals']['not_autoloaded'];
    arsort( $na );
    echo '<h3>Non-autoloaded options (most used first)</h3>';
    echo '<table class="widefat striped"><thead><tr><th>Option name</th><th>Size (kb)</th><th>Total uses</th><th>Frequency (%)</th></tr></thead><tbody>';
    foreach ( $na as $opt => $meta ) {
        $freq = $stats['total_slugs'] > 0 ? round( ( $meta['count'] / $stats['total_slugs'] ) * 100, 1 ) : 0;
        echo '<tr><td>' . esc_html( $opt ) . '</td><td>' . esc_html($meta['size']) . '</td><td>' . (int) $meta['count'] . '</td><td>' . esc_html( $freq ) . '</td></tr>';
    }
    echo '</tbody></table>';

    // Autoloaded options ranked by lowest uses (including zero)
    $al = $stats['totals']['autoloaded'];
    asort( $al );
    echo '<h3>Autoloaded options (least used first)</h3>';
    echo '<table class="widefat striped"><thead><tr><th>Option name</th><th>Size (kb)</th><th>URLs used on</th><th>Frequency (%)</th></tr></thead><tbody>';
    foreach ( $al as $opt => $meta ) {
		$count = $meta['count'];
        $freq = $stats['total_slugs'] > 0 ? round( ( $count / $stats['total_slugs'] ) * 100, 1 ) : 0;
        echo '<tr><td>' . esc_html( $opt ) . '</td><td>' . esc_html($meta['size']) . '</td><td>' . (int) $count . '</td><td>' . esc_html( $freq ) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function cpo_run_autoload_update() {
    global $wpdb;

    $settings = cpo_load_settings();
    $log      = cpo_load_log();
    $stats    = cpo_compute_stats( $log );

    $total_slugs = max( 0, (int) $stats['total_slugs'] );
    if ( $total_slugs === 0 ) {
        return [
            'summary' => 'No changes made: no profiling data available.',
            'changes' => [],
            'notes'   => 'Collect traffic first, then run the update.',
        ];
    }

    $auto_th   = (int) $settings['autoload_threshold'];
    $noauto_th = (int) $settings['noautoload_threshold'];

    // Build a map of current autoload flags
    $changes      = [];

    // Helper to fetch current option data
    $get_current_opt = function( $name ) use ( $wpdb ) {
		$sql = $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", $name );
        return $wpdb->get_var( $sql );
    };
    
    // Delete expired transients?
    if ( !$settings['test_mode'] ) {
		delete_expired_transients();
    }

    // 1) Consider promoting frequently used non-autoloaded options
    foreach ( $stats['totals']['not_autoloaded'] as $opt => $meta ) {
        
        //never autoload?
        if($meta['size'] >= $settings['size_upper_threshold']) {
			continue;
        }
        
        $current = $get_current_opt( $opt );
        $freq = ( $meta['count'] / $total_slugs ) * 100.0;
        $should_autoload = $freq >= $auto_th || $meta['size'] <= $settings['size_lower_threshold'];
        
        //try to cast default?
        if($meta['default'] === false || $meta['default'] === null) {
			if(stripos($opt, 'enable') !== false || stripos($opt, 'disable') !== false) {
				$meta['default'] = 0;
			} else {
				$meta['default'] = false;
			}
        }

		//try to create option?
		if($current === null && !$settings['test_mode']) {
			update_option($opt, $meta['default'], $should_autoload);
		}
            
		if ( $should_autoload && $current && !in_array($current, [ 'yes', 'on', 'auto-on', 'auto' ]) ) {
            
			if ($settings['test_mode']) {
				$updated = true;
			} else {
				$updated = $wpdb->update(
					$wpdb->options,
					[ 'autoload' => 'on' ],
					[ 'option_name' => $opt ],
					[ '%s' ],
					[ '%s' ]
				);
			}

			if ( $updated !== false ) {
				$changes[] = [
					'option' => $opt,
					'from'   => (string) $current,
					'to'     => 'on',
					'reason' => 'Promoted: used on ' . round( $freq, 1 ) . '% of slugs (>= ' . $auto_th . '%).',
				];
			}

		}

    }

    // 2) Consider demoting rarely used autoloaded options
    foreach ( $stats['totals']['autoloaded'] as $opt => $meta ) {
        
        //always autoload?
        if ( $meta['size'] <= $settings['size_lower_threshold'] ) {
			continue;
        }
        
		$current = $get_current_opt( $opt );
        $freq = ( $meta['count'] / $total_slugs ) * 100.0;
        $should_autoload = $freq > $noauto_th && $meta['size'] < $settings['size_upper_threshold'];

		if ( !$should_autoload && $current && !in_array($current, [ 'no', 'off', 'auto-off', 'auto' ]) ) {

			if ($settings['test_mode']) {
				$updated = true;
			} else {
				$updated = $wpdb->update(
					$wpdb->options,
					[ 'autoload' => 'off' ],
					[ 'option_name' => $opt ],
					[ '%s' ],
					[ '%s' ]
				);
			}

			if ( $updated !== false ) {
				$changes[] = [
					'option' => $opt,
					'from'   => (string) $current,
					'to'     => 'off',
					'reason' => 'Demoted: used on ' . round( $freq, 1 ) . '% of slugs (<= ' . $noauto_th . '%).',
				];
			}
		
		}

    }

    $summary = empty( $changes )
        ? 'No changes made based on current thresholds.'
        : ( count( $changes ) . ' option(s) updated.' );

    return [
        'summary' => $summary,
        'changes' => $changes,
        'notes'   => 'You can adjust thresholds and re-run as profiling data evolves.',
    ];
}