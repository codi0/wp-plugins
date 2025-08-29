<?php
// File: vendor/Cef/Admin/ActionsController.php

namespace Cef\Admin;

use Cef\Registry\ActionRegistry;
use Cef\Registry\ConditionRegistry;
use Cef\Registry\EventRegistry;

defined('ABSPATH') || exit;

/**
 * Unified Actions admin:
 * - Top-level menu landing on Actions list.
 * - Dedicated Add/Edit view with schema-driven config form.
 * - Embedded dynamic Rules builder (AJAX-powered) per action.
 * - Preserves prefill_event linking and redirect back to Link Manager.
 * - Retains all functionality from previous ActionsAdmin + RulesAdmin.
 */
class ActionsController
{
    protected $actions;
    protected $conditions;
    protected $events;

    public function __construct(ActionRegistry $actions, ConditionRegistry $conditions, EventRegistry $events)
    {
        $this->actions    = $actions;
        $this->conditions = $conditions;
        $this->events     = $events;

        // Top-level menu points to Actions list by default.
        add_action('admin_menu', [$this, 'register_menu']);

        // AJAX for dynamic provider operator/value rendering.
        add_action('wp_ajax_cef_get_provider_meta', [$this, 'ajax_get_provider_meta']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Events Manager', 'cef'),
            __('Events Manager', 'cef'),
            'manage_options',
            'cef_actions',
            [$this, 'render_router'],
            'dashicons-email-alt2',
            56
        );

        // Keep the Actions item explicitly (even though it's the parent) for clarity.
        add_submenu_page(
            'cef_actions',
            __('Configure', 'cef'),
            __('Configure', 'cef'),
            'manage_options',
            'cef_actions',
            [$this, 'render_router']
        );
    }

    /**
     * Route between list and edit views.
     */
    public function render_router(): void
    {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';

        if (!empty($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            echo '<div class="updated"><p>' . esc_html($msg) . '</p></div>';
        }

        if ($view === 'edit') {
            $this->render_edit_view();
        } else {
            $this->render_list_view();
        }
    }

    /**
     * Actions list table with primary "Add Action" button.
     */
    protected function render_list_view(): void
    {
        global $wpdb;
        $actions_table = $wpdb->prefix . 'cef_actions';
        $links_table   = $wpdb->prefix . 'cef_event_actions';

        // Filters (optional)
        $filter_type    = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $filter_enabled = isset($_GET['enabled']) ? (int) $_GET['enabled'] : -1;

        $where = [];
        $params = [];
        if ($filter_type !== '') {
            $where[] = 'a.type = %s';
            $params[] = $filter_type;
        }
        if ($filter_enabled === 0 || $filter_enabled === 1) {
            $where[] = 'a.enabled = %d';
            $params[] = $filter_enabled;
        }
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Fetch with linked events count
        $sql = "
            SELECT a.*, COALESCE(link_counts.linked_count, 0) AS linked_count
            FROM {$actions_table} a
            LEFT JOIN (
                SELECT action_id, COUNT(*) AS linked_count
                FROM {$links_table}
                WHERE enabled = 1
                GROUP BY action_id
            ) link_counts ON link_counts.action_id = a.id
            {$where_sql}
            ORDER BY a.id DESC
        ";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        // Action types list for filters
        $types = array_keys($this->actions->all());

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Actions', 'cef') . '</h1> ';
        echo '<a href="' . esc_url(add_query_arg(['page' => 'cef_actions', 'view' => 'edit', 'new' => '1'], admin_url('admin.php'))) . '" class="page-title-action">' . esc_html__('Add Action', 'cef') . '</a>';
        echo '<hr class="wp-header-end">';

        // Filters
        echo '<form method="get" style="margin: 10px 0;">';
        echo '<input type="hidden" name="page" value="cef_actions">';
        echo '<label>' . esc_html__('Type', 'cef') . ': ';
        echo '<select name="type"><option value="">' . esc_html__('All', 'cef') . '</option>';
        foreach ($types as $t) {
            printf('<option value="%s" %s>%s</option>',
                esc_attr($t),
                selected($filter_type, $t, false),
                esc_html($t)
            );
        }
        echo '</select></label> ';

        echo '<label>' . esc_html__('Enabled', 'cef') . ': ';
        echo '<select name="enabled">';
        echo '<option value="-1"' . selected($filter_enabled, -1, false) . '>' . esc_html__('All', 'cef') . '</option>';
        echo '<option value="1"' . selected($filter_enabled, 1, false) . '>' . esc_html__('Yes', 'cef') . '</option>';
        echo '<option value="0"' . selected($filter_enabled, 0, false) . '>' . esc_html__('No', 'cef') . '</option>';
        echo '</select></label> ';
        submit_button(__('Filter'), 'secondary', '', false);
        echo '</form>';

        // List table
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'cef') . '</th>';
        echo '<th>' . esc_html__('Name', 'cef') . '</th>';
        echo '<th>' . esc_html__('Type', 'cef') . '</th>';
        echo '<th>' . esc_html__('Enabled', 'cef') . '</th>';
        echo '<th>' . esc_html__('Linked events', 'cef') . '</th>';
        echo '<th>' . esc_html__('Actions', 'cef') . '</th>';
        echo '</tr></thead><tbody>';

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $edit_url = add_query_arg(['page' => 'cef_actions', 'view' => 'edit', 'id' => (int)$row['id']], admin_url('admin.php'));
                echo '<tr>';
                echo '<td>' . (int)$row['id'] . '</td>';
                echo '<td>' . esc_html($row['name']) . '</td>';
                echo '<td><code>' . esc_html($row['type']) . '</code></td>';
                echo '<td>' . ($row['enabled'] ? esc_html__('Yes', 'cef') : esc_html__('No', 'cef')) . '</td>';
                echo '<td>' . (int)$row['linked_count'] . '</td>';
                echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'cef') . '</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">' . esc_html__('No actions found.', 'cef') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Add/Edit view with schema-driven config and embedded rules builder.
     * Preserves prefill_event linking flow and dynamic provider fields.
     */
    protected function render_edit_view(): void
    {
        global $wpdb;
        $actions_table = $wpdb->prefix . 'cef_actions';
        $rules_table   = $wpdb->prefix . 'cef_rules';

        // Types and defs
        $defs  = $this->actions->all();
        $types = array_keys($defs);

        $is_post         = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $is_refresh      = $is_post && !empty($_POST['cef_refresh']);
        $has_valid_nonce = $is_post && !empty($_POST['cef_action_nonce']) && wp_verify_nonce($_POST['cef_action_nonce'], 'save_cef_action_and_rules');

        $prefill_event = isset($_GET['prefill_event']) ? sanitize_text_field($_GET['prefill_event']) : '';
        if ($is_post && isset($_POST['prefill_event'])) {
            $prefill_event = sanitize_text_field($_POST['prefill_event']);
        }

        // Handle SAVE (not refresh)
        if ($has_valid_nonce && !$is_refresh) {
            $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $type    = sanitize_text_field($_POST['type'] ?? '');
            $name    = sanitize_text_field($_POST['name'] ?? '');
            $enabled = !empty($_POST['enabled']) ? 1 : 0;

            // Build config from schema or fallback JSON
            $action_def = $this->actions->get($type);
            if ($action_def && !empty($action_def['config_schema']) && is_array($action_def['config_schema'])) {
                $config = $this->clean_config_from_schema($action_def['config_schema'], $_POST['config'] ?? []);
            } else {
                $raw    = wp_unslash($_POST['config_json'] ?? '');
                $config = json_decode($raw, true) ?: [];
            }

            if ($id > 0) {
                $wpdb->update($actions_table, [
                    'type'        => $type,
                    'name'        => $name,
                    'enabled'     => $enabled,
                    'config_json' => wp_json_encode($config),
                ], ['id' => $id]);
            } else {
                $wpdb->insert($actions_table, [
                    'type'        => $type,
                    'name'        => $name,
                    'enabled'     => $enabled,
                    'config_json' => wp_json_encode($config),
                ]);
                $id = (int)$wpdb->insert_id;
            }

            // Save Rules for this action
            $this->save_rules_for_action($rules_table, $id, $_POST['rules'] ?? []);

            // Redirect back to list with message (avoid resubmission)
            wp_safe_redirect(add_query_arg([
                'page'    => 'cef_actions',
                'message' => rawurlencode(__('Action saved.', 'cef')),
            ], admin_url('admin.php')));
            exit;
        }

        // Load edit row if present
        $edit_row = null;
        if (!empty($_GET['id'])) {
            $edit_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $actions_table WHERE id = %d",
                (int) $_GET['id']
            ), ARRAY_A);
        }

        // Determine selected type
        $selected_type = '';
        if ($is_post) {
            $selected_type = sanitize_text_field($_POST['type'] ?? '');
        } elseif ($edit_row) {
            $selected_type = (string) ($edit_row['type'] ?? '');
        } elseif (!empty($types)) {
            $selected_type = $types[0];
        }
        $action_def = $selected_type ? $this->actions->get($selected_type) : null;

        // Determine current config (prefer POST on refresh)
        $current_config = [];
        if ($is_post) {
            $current_config = $_POST['config'] ?? [];
        } elseif ($edit_row) {
            $current_config = json_decode($edit_row['config_json'] ?? '{}', true) ?: [];
        }

        // Existing rules for this action
        $existing_rules = [];
        if (!empty($edit_row['id'])) {
            $existing_rules = $this->get_rules_grouped($rules_table, (int)$edit_row['id']);
        } else {
            $existing_rules = [1 => []];
        }

        // Provider registry
        $providers = $this->conditions->all(true);

		// All registered events
		$all_events = $this->events->all(true); // true = only enabled

		// Existing linked events for this action
		$linked_events = [];
		if (!empty($edit_row['id'])) {
			$linked_events = $wpdb->get_col($wpdb->prepare(
				"SELECT event_key FROM {$wpdb->prefix}cef_event_actions WHERE action_id = %d AND enabled = 1",
				(int)$edit_row['id']
			));
		}

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . ($edit_row ? esc_html__('Edit Action', 'cef') : esc_html__('Add Action', 'cef')) . '</h1> ';
        echo '<a href="' . esc_url(add_query_arg(['page' => 'cef_actions'], admin_url('admin.php'))) . '" class="page-title-action">' . esc_html__('Back to list', 'cef') . '</a>';
        echo '<hr class="wp-header-end">';

        ?>
        <form method="post" id="cef-action-form">
            <?php wp_nonce_field('save_cef_action_and_rules', 'cef_action_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo esc_attr($edit_row['id'] ?? 0); ?>">
            <input type="hidden" id="cef_refresh" name="cef_refresh" value="">
            <input type="hidden" name="prefill_event" value="<?php echo esc_attr($prefill_event); ?>">

            <h2><?php esc_html_e('Action details', 'cef'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="type"><?php esc_html_e('Type', 'cef'); ?></label></th>
                    <td>
                        <select name="type" id="type" required onchange="document.getElementById('cef_refresh').value='1'; this.form.submit();">
                            <?php foreach ($types as $t): ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($selected_type, $t); ?>>
                                    <?php echo esc_html($t); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Changing type will reload configuration fields without saving.', 'cef'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="name"><?php esc_html_e('Name', 'cef'); ?></label></th>
                    <td><input type="text" name="name" id="name" value="<?php echo esc_attr($edit_row['name'] ?? ($_POST['name'] ?? '')); ?>" required style="min-width:300px;"></td>
                </tr>
                <tr>
                    <th><label for="enabled"><?php esc_html_e('Enabled', 'cef'); ?></label></th>
                    <td><input type="checkbox" name="enabled" id="enabled" value="1" <?php
                        $enabled_val = $edit_row['enabled'] ?? ($_POST['enabled'] ?? 1);
                        checked((int)$enabled_val, 1);
                    ?>></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Configuration', 'cef'); ?></h2>
            <table class="form-table">
                <?php
                if ($action_def && !empty($action_def['config_schema']) && is_array($action_def['config_schema'])) {
                    foreach ($action_def['config_schema'] as $key => $field) {
                        $label = $field['label'] ?? $key;
                        $type  = $field['type']  ?? 'text';
                        $desc  = $field['description'] ?? '';
                        $opts  = $field['options'] ?? [];
                        $val   = $current_config[$key] ?? '';

                        echo '<tr><th><label for="config_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';

                        switch ($type) {
                            case 'number':
                                echo '<input type="number" step="any" id="config_' . esc_attr($key) . '" name="config[' . esc_attr($key) . ']" value="' . esc_attr($val) . '">';
                                break;
                            case 'textarea':
                                echo '<textarea id="config_' . esc_attr($key) . '" name="config[' . esc_attr($key) . ']" rows="4" cols="60">' . esc_textarea($val) . '</textarea>';
                                break;
                            case 'wysiwyg':
                                $val_str = is_string($val) ? $val : '';
                                wp_editor($val_str, 'config_' . esc_attr($key), [
                                    'textarea_name' => 'config[' . esc_attr($key) . ']',
                                    'textarea_rows' => 8,
                                ]);
                                break;
                            case 'select':
                                echo '<select id="config_' . esc_attr($key) . '" name="config[' . esc_attr($key) . ']">';
                                foreach ((array)$opts as $ov => $ol) {
                                    printf('<option value="%s" %s>%s</option>',
                                        esc_attr($ov),
                                        selected($val, $ov, false),
                                        esc_html($ol)
                                    );
                                }
                                echo '</select>';
                                break;
                            case 'multi_select':
                                $selected_vals = is_array($val) ? $val : array_filter(array_map('trim', explode(',', (string)$val)));
                                echo '<select multiple id="config_' . esc_attr($key) . '" name="config[' . esc_attr($key) . '][]">';
                                foreach ((array)$opts as $ov => $ol) {
                                    printf('<option value="%s" %s>%s</option>',
                                        esc_attr($ov),
                                        in_array($ov, $selected_vals, true) ? 'selected' : '',
                                        esc_html($ol)
                                    );
                                }
                                echo '</select>';
                                break;
                            case 'text':
                            default:
                                echo '<input type="text" id="config_' . esc_attr($key) . '" name="config[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" style="min-width:300px;">';
                                break;
                        }

                        if (!empty($desc)) {
                            echo '<p class="description">' . esc_html($desc) . '</p>';
                        }
                        echo '</td></tr>';
                    }
                } else {
                    ?>
                    <tr>
                        <th><label for="config_json"><?php esc_html_e('Config (JSON)', 'cef'); ?></label></th>
                        <td>
                            <textarea name="config_json" id="config_json" rows="6" cols="60"><?php
                                echo esc_textarea($edit_row['config_json'] ?? ($_POST['config_json'] ?? '{}'));
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Enter action configuration as JSON. Fields depend on action type.', 'cef'); ?></p>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>

			<h2><?php esc_html_e('Trigger Events', 'cef'); ?></h2>
			<p><?php esc_html_e('Select the events that should trigger this action.', 'cef'); ?></p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e('Events', 'cef'); ?></th>
					<td>
						<?php if (empty($all_events)): ?>
							<em><?php esc_html_e('No events are currently registered.', 'cef'); ?></em>
						<?php else: ?>
							<?php foreach ($all_events as $event_key => $event_def): ?>
								<label style="display:block; margin-bottom:4px;">
									<input type="checkbox" name="linked_events[]" value="<?php echo esc_attr($event_key); ?>"
										<?php checked(in_array($event_key, $linked_events, true)); ?>>
									<strong><?php echo esc_html($event_def['label'] ?? $event_key); ?></strong>
									<code><?php echo esc_html($event_key); ?></code>
									<?php if (!empty($event_def['description'])): ?>
										<span class="description"><?php echo esc_html($event_def['description']); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

            <h2><?php esc_html_e('Conditions', 'cef'); ?></h2>
            <?php $this->render_rules_builder($existing_rules, $providers, (int)($edit_row['id'] ?? 0)); ?>

            <?php submit_button($edit_row ? __('Save Action', 'cef') : __('Create Action', 'cef')); ?>
        </form>
        <?php
        echo '</div>';
    }

    /**
     * Render the enhanced rules builder UI (dynamic add/remove, AJAX provider fields).
     */
    protected function render_rules_builder(array $groups, array $providers, int $action_id): void
    {
        $ajax_nonce = wp_create_nonce('cef_rules_ajax');

        // Basic styles (scoped)
        echo '<style>
            .cef-rule-group { border:1px solid #ccd0d4; padding:12px; margin:0 0 16px 0; background:#fff; }
            .cef-rule-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
            .cef-cell { display:inline-block; }
            .cef-provider-cell select { min-width:220px; }
            .cef-operator-cell select, .cef-operator-cell input { min-width:120px; }
            .cef-value-cell select, .cef-value-cell input, .cef-value-cell textarea { min-width:220px; }
            .cef-help { color:#555; font-size:12px; }
            .cef-or-sep { text-align:center; font-style:italic; margin:6px 0 12px; color:#555; }
            .button-link { background:none; border:none; color:#2271b1; cursor:pointer; padding:0; }
        </style>';

        echo '<input type="hidden" id="cef_rules_ajax_nonce" value="' . esc_attr($ajax_nonce) . '">';

        echo '<p>' . esc_html__('Logic: All rules within a group must pass (AND). If any group passes, the action runs (OR between groups).', 'cef') . '</p>';

        echo '<div id="cef-rules-builder">';
        $total_groups = count($groups);
        $i = 0;
        foreach ($groups as $group_index => $rules) {
            $i++;
            echo '<div class="cef-rule-group" data-group-index="' . esc_attr($group_index) . '">';
            echo '<div style="margin-bottom:8px; font-weight:bold;">' . sprintf(esc_html__('Group %d — ALL conditions must pass', 'cef'), (int)$group_index) . '</div>';
            echo '<div class="cef-rules">';
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    echo $this->render_rule_row_html($group_index, $rule, $providers);
                }
            }
            // One blank row
            echo $this->render_rule_row_html($group_index, [], $providers);
            echo '</div>';
            echo '<div class="cef-group-actions" style="margin-top:8px;">
                    <button type="button" class="button add-rule">' . esc_html__('+ Add Rule', 'cef') . '</button>
                    <button type="button" class="button remove-group">' . esc_html__('Remove Group', 'cef') . '</button>
                  </div>';
            echo '</div>';
            if ($i < $total_groups) {
                echo '<div class="cef-or-sep">' . esc_html__('OR', 'cef') . '</div>';
            }
        }
        echo '</div>';

        echo '<div style="margin:12px 0;">
                <button type="button" class="button" id="add-group">' . esc_html__('+ Add Group', 'cef') . '</button>
              </div>';

        // Hidden template
        $blank_rule_template = $this->render_rule_row_html('__GROUP__', [], $providers, true);
        echo '<script type="text/template" id="cef-rule-row-template">' . $blank_rule_template . '</script>';

        // JS
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        (function(){
            const ajaxUrl = "<?= esc_js($ajax_url) ?>";
            const ajaxNonce = document.getElementById("cef_rules_ajax_nonce").value;
            const builder = document.getElementById("cef-rules-builder");

            function updateOrSeparators() {
                builder.querySelectorAll(".cef-or-sep").forEach(el => el.remove());
                const groups = builder.querySelectorAll(".cef-rule-group");
                groups.forEach((g, idx) => {
                    if (idx < groups.length - 1) {
                        const sep = document.createElement("div");
                        sep.className = "cef-or-sep";
                        sep.textContent = "OR";
                        g.after(sep);
                    }
                });
            }

            async function loadProviderFields(rowEl, providerKey) {
                const groupIndex = rowEl.closest(".cef-rule-group").getAttribute("data-group-index");
                const formData = new FormData();
                formData.append("action", "cef_get_provider_meta");
                formData.append("nonce", ajaxNonce);
                formData.append("provider_key", providerKey);
                formData.append("group_index", groupIndex);

                try {
                    const resp = await fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: formData });
                    const data = await resp.json();
                    if (data && data.success && data.data) {
                        rowEl.querySelector(".cef-operator-cell").innerHTML = data.data.operator_html || "";
                        rowEl.querySelector(".cef-value-cell").innerHTML = data.data.value_html || "";
                        const helpEl = rowEl.querySelector(".cef-help");
                        if (helpEl) helpEl.innerHTML = data.data.help_html || "";
                    } else {
                        rowEl.querySelector(".cef-operator-cell").innerHTML = "<input type=\"text\" name=\"rules["+groupIndex+"][][operator]\" placeholder=\"Operator\">";
                        rowEl.querySelector(".cef-value-cell").innerHTML    = "<input type=\"text\" name=\"rules["+groupIndex+"][][value]\" placeholder=\"Value\">";
                    }
                } catch (e) {}
            }

            builder.addEventListener("change", function(e){
                const t = e.target;
                if (t.classList.contains("cef-provider-select")) {
                    const rowEl = t.closest(".cef-rule-row");
                    const providerKey = t.value;
                    rowEl.querySelector(".cef-operator-cell").innerHTML = "";
                    rowEl.querySelector(".cef-value-cell").innerHTML = "";
                    if (providerKey) {
                        loadProviderFields(rowEl, providerKey);
                    }
                }
            });

            builder.addEventListener("click", function(e){
                const t = e.target;

                if (t.classList.contains("add-rule")) {
                    const groupEl = t.closest(".cef-rule-group");
                    const groupIndex = groupEl.getAttribute("data-group-index");
                    const tpl = document.getElementById("cef-rule-row-template").textContent;
                    const html = tpl.replace(/__GROUP__/g, groupIndex);
                    const temp = document.createElement("div");
                    temp.innerHTML = html.trim();
                    groupEl.querySelector(".cef-rules").appendChild(temp.firstElementChild);
                }

                if (t.classList.contains("remove-group")) {
                    t.closest(".cef-rule-group").remove();
                    updateOrSeparators();
                }

                if (t.classList.contains("cef-remove-rule")) {
                    const rowEl = t.closest(".cef-rule-row");
                    const groupEl = t.closest(".cef-rule-group");
                    rowEl.remove();
                    // Ensure at least one row remains
                    if (!groupEl.querySelector(".cef-rule-row")) {
                        const groupIndex = groupEl.getAttribute("data-group-index");
                        const tpl = document.getElementById("cef-rule-row-template").textContent;
                        const html = tpl.replace(/__GROUP__/g, groupIndex);
                        const temp = document.createElement("div");
                        temp.innerHTML = html.trim();
                        groupEl.querySelector(".cef-rules").appendChild(temp.firstElementChild);
                    }
                }
            });

            document.getElementById("add-group").addEventListener("click", function(){
                const groups = builder.querySelectorAll(".cef-rule-group");
                let maxIdx = 0;
                groups.forEach(g => {
                    const v = parseInt(g.getAttribute("data-group-index"), 10);
                    if (!isNaN(v) && v > maxIdx) maxIdx = v;
                });
                const nextIdx = maxIdx + 1;

                const group = document.createElement("div");
                group.className = "cef-rule-group";
                group.setAttribute("data-group-index", String(nextIdx));
                group.innerHTML = `
                    <div style="margin-bottom:8px; font-weight:bold;"><?php echo esc_js(__('Group', 'cef')); ?> ${nextIdx} — <?php echo esc_js(__('ALL conditions must pass', 'cef')); ?></div>
                    <div class="cef-rules"></div>
                    <div class="cef-group-actions" style="margin-top:8px;">
                        <button type="button" class="button add-rule"><?php echo esc_js(__('+ Add Rule', 'cef')); ?></button>
                        <button type="button" class="button remove-group"><?php echo esc_js(__('Remove Group', 'cef')); ?></button>
                    </div>
                `.trim();

                const tpl = document.getElementById("cef-rule-row-template").textContent;
                const html = tpl.replace(/__GROUP__/g, String(nextIdx));
                const temp = document.createElement("div");
                temp.innerHTML = html.trim();
                group.querySelector(".cef-rules").appendChild(temp.firstElementChild);

                builder.appendChild(group);
                updateOrSeparators();
            });

            updateOrSeparators();
        })();
        </script>
        <?php
    }

    /**
     * Save rules for a given action ID.
     */
    protected function save_rules_for_action(string $rules_table, int $action_id, $rules_post): void
    {
        global $wpdb;

        // Clear existing rule rows for the target
        $wpdb->delete($rules_table, [
            'target_kind' => 'action',
            'target_id'   => $action_id,
        ]);

        if (empty($rules_post) || !is_array($rules_post)) {
            return;
        }

        foreach ($rules_post as $group_index => $group_rules) {
            if (!is_array($group_rules)) {
                continue;
            }
            foreach ($group_rules as $rule) {
                $provider_key = isset($rule['provider_key']) ? sanitize_key($rule['provider_key']) : '';
                if ($provider_key === '') {
                    continue;
                }
                $operator = sanitize_text_field($rule['operator'] ?? '');
                $value    = $rule['value'] ?? '';
                if (is_array($value)) {
                    $value = implode(',', array_map('sanitize_text_field', $value));
                } else {
                    $value = sanitize_text_field($value);
                }

                $wpdb->insert($rules_table, [
                    'target_kind'  => 'action',
                    'target_id'    => $action_id,
                    'group_index'  => (int)$group_index,
                    'provider_key' => $provider_key,
                    'operator'     => $operator,
                    'value_text'   => $value,
                ]);
            }
        }

		// Save linked events
		$wpdb->delete($wpdb->prefix . 'cef_event_actions', ['action_id' => $action_id]);

		if (!empty($_POST['linked_events']) && is_array($_POST['linked_events'])) {
			foreach ($_POST['linked_events'] as $event_key) {
				$wpdb->insert($wpdb->prefix . 'cef_event_actions', [
					'event_key'  => sanitize_text_field($event_key),
					'action_id'  => $action_id,
					'enabled'    => 1,
					'created_at' => current_time('mysql', true),
					'updated_at' => current_time('mysql', true),
				]);
			}
		}
    }

    /**
     * Fetch rules for an action and group them by group_index.
     */
    protected function get_rules_grouped(string $rules_table, int $action_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $rules_table WHERE target_kind = 'action' AND target_id = %d ORDER BY group_index ASC, id ASC",
            $action_id
        ), ARRAY_A);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['group_index']][] = $row;
        }
        if (empty($groups)) {
            $groups[1] = [];
        }
        return $groups;
    }

    /**
     * Render a single rule row (HTML string).
     *
     * @param int|string $group_index
     * @param array      $rule
     * @param array      $providers
     * @param bool       $for_template If true, output with placeholder group index.
     * @return string
     */
    protected function render_rule_row_html($group_index, array $rule, array $providers, bool $for_template = false): string
    {
        $provider_key = $rule['provider_key'] ?? '';
        $operator     = $rule['operator'] ?? '';
        $value_text   = $rule['value_text'] ?? '';

        ob_start();
        ?>
        <div class="cef-rule-row">
            <div class="cef-cell cef-provider-cell">
                <select name="rules[<?php echo esc_attr($group_index); ?>][][provider_key]" class="cef-provider-select">
                    <option value=""><?php esc_html_e('Select condition…', 'cef'); ?></option>
                    <?php foreach ($providers as $key => $def): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($provider_key, $key); ?>>
                            <?php echo esc_html($def['label'] ?? $key); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cef-cell cef-operator-cell">
                <?php
                if ($provider_key && isset($providers[$provider_key])) {
                    echo $this->build_operator_html($providers[$provider_key], $operator, (string)$group_index);
                } else {
                    printf(
                        '<input type="text" name="rules[%s][][operator]" value="%s" placeholder="%s">',
                        esc_attr($group_index),
                        esc_attr($operator),
                        esc_attr__('Operator', 'cef')
                    );
                }
                ?>
            </div>

            <div class="cef-cell cef-value-cell">
                <?php
                if ($provider_key && isset($providers[$provider_key])) {
                    echo $this->build_value_html($providers[$provider_key], $value_text, (string)$group_index);
                } else {
                    printf(
                        '<input type="text" name="rules[%s][][value]" value="%s" placeholder="%s">',
                        esc_attr($group_index),
                        esc_attr((string)$value_text),
                        esc_attr__('Value', 'cef')
                    );
                }
                ?>
            </div>

            <div class="cef-cell">
                <button type="button" class="button-link cef-remove-rule"><?php esc_html_e('Remove', 'cef'); ?></button>
            </div>

            <div class="cef-cell" style="flex-basis:100%;">
                <?php
                if ($provider_key && isset($providers[$provider_key]['description'])) {
                    echo '<span class="cef-help">' . esc_html($providers[$provider_key]['description']);
                    if (!empty($providers[$provider_key]['docs_url'])) {
                        echo ' <a href="' . esc_url($providers[$provider_key]['docs_url']) . '" target="_blank" rel="noopener noreferrer">?</a>';
                    }
                    echo '</span>';
                } else {
                    echo '<span class="cef-help"></span>';
                }
                ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        if ($for_template) {
            // Replace the explicit group index with a placeholder for JS insertion
            $html = str_replace(
                ['rules[' . $group_index . ']'],
                ['rules[__GROUP__]'],
                $html
            );
        }

        return $html;
    }

    /**
     * Build operator field HTML for a provider.
     */
    protected function build_operator_html(array $def, string $operator, string $group_index): string
    {
        $ops = $def['operators'] ?? [];
        if (empty($ops) || !is_array($ops)) {
            return sprintf(
                '<input type="text" name="rules[%s][][operator]" value="%s" placeholder="%s">',
                esc_attr($group_index),
                esc_attr($operator),
                esc_attr__('Operator', 'cef')
            );
        }

        $html = sprintf('<select name="rules[%s][][operator]">', esc_attr($group_index));
        foreach ($ops as $op) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($op),
                selected($operator, $op, false),
                esc_html($op)
            );
        }
        $html .= '</select>';
        return $html;
    }

    /**
     * Build value field HTML for a provider.
     */
    protected function build_value_html(array $def, $value, string $group_index): string
    {
        $type = $def['value_type'] ?? 'text';

        switch ($type) {
            case 'none':
                return '';

            case 'number':
                return sprintf(
                    '<input type="number" step="any" name="rules[%s][][value]" value="%s">',
                    esc_attr($group_index),
                    esc_attr((string)$value)
                );

            case 'select_multi':
                $options = [];
                if (!empty($def['options_cb']) && is_callable($def['options_cb'])) {
                    try { $options = (array) call_user_func($def['options_cb']); } catch (\Throwable $e) { $options = []; }
                }
                $selected_vals = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
                $html = sprintf('<select multiple name="rules[%s][][value][]">', esc_attr($group_index));
                foreach ($options as $k => $label) {
                    $sel = in_array((string)$k, array_map('strval', $selected_vals), true) ? ' selected' : '';
                    $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($k), $sel, esc_html($label));
                }
                $html .= '</select>';
                return $html;

            case 'text':
            default:
                return sprintf(
                    '<input type="text" name="rules[%s][][value]" value="%s">',
                    esc_attr($group_index),
                    esc_attr((string)$value)
                );
        }
    }

    /**
     * Clean config array from schema.
     */
    protected function clean_config_from_schema(array $schema, array $input): array
    {
        $clean = [];
        foreach ($schema as $key => $field) {
            $type = $field['type'] ?? 'text';
            $opts = $field['options'] ?? [];

            switch ($type) {
                case 'number':
                    $clean[$key] = isset($input[$key]) && $input[$key] !== '' ? (float) $input[$key] : 0;
                    break;

                case 'textarea':
                    $clean[$key] = isset($input[$key]) ? sanitize_textarea_field((string)$input[$key]) : '';
                    break;

                case 'wysiwyg':
                    // Allow post content HTML
                    $clean[$key] = isset($input[$key]) ? wp_kses_post((string)$input[$key]) : '';
                    break;

                case 'select':
                    $val = isset($input[$key]) ? (string)$input[$key] : '';
                    if (!empty($opts) && !array_key_exists($val, (array)$opts)) {
                        $val = ''; // invalid option -> empty
                    }
                    $clean[$key] = sanitize_text_field($val);
                    break;

                case 'multi_select':
                    $vals = isset($input[$key]) ? (array)$input[$key] : [];
                    $vals = array_map('strval', $vals);
                    if (!empty($opts)) {
                        $vals = array_values(array_intersect(array_keys((array)$opts), $vals));
                    }
                    $clean[$key] = array_map('sanitize_text_field', $vals);
                    break;

                case 'text':
                default:
                    $clean[$key] = isset($input[$key]) ? sanitize_text_field((string)$input[$key]) : '';
                    break;
            }
        }
        return $clean;
    }

    /**
     * AJAX: Return operator/value HTML and help text for a provider and group index.
     */
    public function ajax_get_provider_meta(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('cef_rules_ajax', 'nonce');

        $provider_key = isset($_POST['provider_key']) ? sanitize_key((string)$_POST['provider_key']) : '';
        $group_index  = isset($_POST['group_index']) ? (string) sanitize_text_field($_POST['group_index']) : '';

        if ($provider_key === '' || $group_index === '') {
            wp_send_json_error(['message' => 'missing params'], 400);
        }

        $providers = $this->conditions->all(true);
        if (empty($providers[$provider_key])) {
            wp_send_json_error(['message' => 'unknown provider'], 404);
        }
        $def = $providers[$provider_key];

        $operator_html = $this->build_operator_html($def, '', $group_index);
        $value_html    = $this->build_value_html($def, '', $group_index);

        $help_html = '';
        if (!empty($def['description'])) {
            $help_html = '<span class="cef-help">' . esc_html($def['description']);
            if (!empty($def['docs_url'])) {
                $help_html .= ' <a href="' . esc_url($def['docs_url']) . '" target="_blank" rel="noopener noreferrer">?</a>';
            }
            $help_html .= '</span>';
        }

        wp_send_json_success([
            'operator_html' => $operator_html,
            'value_html'    => $value_html,
            'help_html'     => $help_html,
        ]);
    }
}