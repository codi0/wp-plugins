<?php
// File: vendor/Cef/Admin/JobsAdmin.php

namespace Cef\Admin;

use Cef\Engine\ConditionEngine;
use Cef\Registry\ConditionRegistry;
use Cef\Scheduler\Scheduler;

defined('ABSPATH') || exit;

class JobsController
{
    protected $engine;
    protected $conditions;
    protected $scheduler;

    public function __construct(ConditionEngine $engine, ConditionRegistry $conditions, Scheduler $scheduler)
    {
        $this->engine     = $engine;
        $this->conditions = $conditions;
        $this->scheduler  = $scheduler;
        add_action('admin_menu', [$this, 'register_submenu']);
    }

    public function register_submenu(): void
    {
        add_submenu_page(
            'cef_actions',
            __('History', 'cef'),
            __('History', 'cef'),
            'manage_options',
            'cef_jobs_detail',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'cef_jobs';

        // Handle actions
        if (!empty($_POST['cef_job_action_nonce']) && wp_verify_nonce($_POST['cef_job_action_nonce'], 'cef_job_action')) {
            $job_id = (int) $_POST['job_id'];
            $action = sanitize_text_field($_POST['job_action']);

            if ($action === 'rerun') {
                // Reset job to queued so scheduler will re-evaluate
                $wpdb->update($jobs_table, [
                    'status'          => 'queued',
                    'last_eval_at'    => null,
                    'executed_at'     => null,
                    'result_text'     => null,
                    'earliest_run_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $job_id]);
                echo '<div class="updated"><p>' . esc_html__('Job re-queued for evaluation.', 'cef') . '</p></div>';
            }

            if ($action === 'force_send') {
                // Bypass conditions and execute immediately
                if (method_exists($this->scheduler, 'run_job_force')) {
                    $this->scheduler->run_job_force($job_id);
                } else {
                    // Fallback: try normal run (conditions will still apply)
                    do_action(defined('CEF_CRON_RUN_JOB') ? CEF_CRON_RUN_JOB : 'cef_run_job', $job_id);
                }
                echo '<div class="updated"><p>' . esc_html__('Job executed (force). Check result below.', 'cef') . '</p></div>';
            }
        }

        // Detail view
        if (!empty($_GET['job_id'])) {
            $job_id = (int) $_GET['job_id'];
            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $jobs_table WHERE id = %d",
                $job_id
            ), ARRAY_A);

            if (!$job) {
                echo '<div class="wrap"><h1>' . esc_html__('Job Not Found', 'cef') . '</h1></div>';
                return;
            }

            echo '<div class="wrap"><h1>' . sprintf(esc_html__('Job #%d Details', 'cef'), $job_id) . '</h1>';

            // Action buttons
            ?>
            <form method="post" style="margin-bottom:15px;">
                <?php wp_nonce_field('cef_job_action', 'cef_job_action_nonce'); ?>
                <input type="hidden" name="job_id" value="<?php echo esc_attr($job_id); ?>">
                <button type="submit" name="job_action" value="rerun" class="button button-secondary">
                    <?php esc_html_e('Re-queue for Evaluation', 'cef'); ?>
                </button>
                <button type="submit" name="job_action" value="force_send" class="button button-primary" onclick="return confirm('<?php echo esc_js(__('Execute this job immediately, bypassing conditions?', 'cef')); ?>');">
                    <?php esc_html_e('Force Send Now', 'cef'); ?>
                </button>
            </form>
            <?php

            // Job table
            echo '<table class="widefat fixed striped">';
            foreach ($job as $col => $val) {
                echo '<tr><th>' . esc_html($col) . '</th><td><pre style="white-space:pre-wrap;">' . esc_html((string)$val) . '</pre></td></tr>';
            }
            echo '</table>';

            // Context
            $context = [];
            if (!empty($job['context_json'])) {
                $context = json_decode($job['context_json'], true) ?: [];
                echo '<h2>' . esc_html__('Context', 'cef') . '</h2>';
                echo '<pre style="background:#f9f9f9; padding:10px; border:1px solid #ccc;">' . esc_html(print_r($context, true)) . '</pre>';
            }

            // Evaluation trace
            echo '<h2>' . esc_html__('Condition Evaluation Trace', 'cef') . '</h2>';
            $trace = $this->evaluate_with_trace('action', (int)$job['action_id'], $context);
            if (empty($trace)) {
                echo '<p>' . esc_html__('No conditions found for this action.', 'cef') . '</p>';
            } else {
                echo '<table class="widefat fixed striped">';
                echo '<thead><tr><th>Group</th><th>Provider</th><th>Operator</th><th>Value</th><th>Result</th></tr></thead><tbody>';
                foreach ($trace as $row) {
                    printf(
                        '<tr>
                            <td>%d</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td style="color:%s;font-weight:bold;">%s</td>
                        </tr>',
                        $row['group_index'],
                        esc_html($row['provider_label']),
                        esc_html($row['operator']),
                        esc_html(is_array($row['value']) ? implode(',', $row['value']) : (string)$row['value']),
                        $row['passed'] ? 'green' : 'red',
                        $row['passed'] ? __('PASS', 'cef') : __('FAIL', 'cef')
                    );
                }
                echo '</tbody></table>';
            }

            echo '<p><a href="' . esc_url(remove_query_arg('job_id')) . '">&larr; ' . esc_html__('Back to Jobs List', 'cef') . '</a></p>';
            echo '</div>';
            return;
        }

        // List view
        $rows = $wpdb->get_results("SELECT * FROM $jobs_table ORDER BY id DESC LIMIT 50", ARRAY_A);

        echo '<div class="wrap"><h1>' . esc_html__('Recent Jobs', 'cef') . '</h1>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Event</th><th>Action ID</th><th>Status</th><th>Triggered</th><th>Earliest Run</th><th>Executed</th><th>View</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            printf(
                '<tr>
                    <td>%d</td>
                    <td>%s</td>
                    <td>%d</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><a href="%s">%s</a></td>
                </tr>',
                $row['id'],
                esc_html($row['event_key']),
                $row['action_id'],
                esc_html($row['status']),
                esc_html($row['triggered_at']),
                esc_html($row['earliest_run_at']),
                esc_html($row['executed_at']),
                esc_url(add_query_arg('job_id', $row['id'])),
                esc_html__('View', 'cef')
            );
        }
        echo '</tbody></table></div>';
    }

    /**
     * Evaluate with trace — returns an array of each rule's result.
     */
    protected function evaluate_with_trace(string $target_kind, int $target_id, array $context): array
    {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'cef_rules';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $rules_table WHERE target_kind = %s AND target_id = %d ORDER BY group_index ASC, id ASC",
                $target_kind,
                $target_id
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        $trace = [];
        foreach ($rows as $row) {
            $provider = $this->conditions->get($row['provider_key']);
            if (!$provider) {
                $trace[] = [
                    'group_index'    => (int)$row['group_index'],
                    'provider_label' => $row['provider_key'] . ' (missing)',
                    'operator'       => $row['operator'],
                    'value'          => $row['value_text'],
                    'passed'         => false,
                ];
                continue;
            }

            $value = $row['value_text'];
            if (($provider['value_type'] ?? '') === 'number') {
                $value = is_numeric($value) ? (float)$value : 0;
            } elseif (($provider['value_type'] ?? '') === 'select_multi') {
                $value = is_string($value) ? array_map('trim', explode(',', $value)) : (array)$value;
            }

            $passed = (bool) call_user_func(
                $provider['eval_cb'],
                $context,
                $row['operator'],
                $value
            );

            $trace[] = [
                'group_index'    => (int)$row['group_index'],
                'provider_label' => $provider['label'] ?? $row['provider_key'],
                'operator'       => $row['operator'],
                'value'          => $value,
                'passed'         => $passed,
            ];
        }

        return $trace;
    }
}