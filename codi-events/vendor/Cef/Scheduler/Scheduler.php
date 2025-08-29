<?php
// File: vendor/Cef/Scheduler/Scheduler.php

namespace Cef\Scheduler;

use Cef\Engine\ConditionEngine;
use Cef\Registry\ActionRegistry;
use Cef\Registry\EventRegistry;
use Cef\Templating\Templating;

defined('ABSPATH') || exit;

class Scheduler
{
    protected $engine;
    protected $actions;
    protected $events;
    protected $templating;

    public function __construct(
        ConditionEngine $engine,
        ActionRegistry $actions,
        EventRegistry $events,
        Templating $templating
    ) {
        $this->engine     = $engine;
        $this->actions    = $actions;
        $this->events     = $events;
        $this->templating = $templating;
    }

    /**
     * Called by EventRegistry when an event fires.
     * Only enqueues actions linked to this event in cef_event_actions.
     */
    public function enqueue_for_event(string $event_key, array $context, ?string $dedupe_key, int $debounce_seconds): void
    {
        global $wpdb;
        $jobs_table    = $wpdb->prefix . 'cef_jobs';
        $link_table    = $wpdb->prefix . 'cef_event_actions';
        $actions_table = $wpdb->prefix . 'cef_actions';

        // Find enabled actions linked to this event
        $actions = $wpdb->get_col($wpdb->prepare(
            "SELECT ea.action_id 
             FROM $link_table ea 
             INNER JOIN $actions_table a ON a.id = ea.action_id AND a.enabled = 1
             WHERE ea.enabled = 1 AND ea.event_key = %s",
            $event_key
        ));
        if (empty($actions)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        foreach ($actions as $action_id) {
            // Scoped dedupe: event + action + dedupe_key
            if ($dedupe_key && $debounce_seconds > 0) {
                $recent = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $jobs_table
                     WHERE event_key = %s
                       AND action_id = %d
                       AND dedupe_key = %s
                       AND triggered_at >= %s
                       AND status = 'queued'
                     LIMIT 1",
                    $event_key,
                    (int)$action_id,
                    $dedupe_key,
                    gmdate('Y-m-d H:i:s', time() - $debounce_seconds)
                ));
                if ($recent) {
                    continue;
                }
            }

            $wpdb->insert($jobs_table, [
                'event_key'       => $event_key,
                'action_id'       => (int)$action_id,
                'status'          => 'queued',
                'triggered_at'    => $now,
                'earliest_run_at' => $now,
                'dedupe_key'      => $dedupe_key,
                'context_json'    => wp_json_encode($context),
                'attempts'        => 0,
            ]);
        }
    }

    /**
     * Cron tick: dispatch due jobs.
     */
    public function dispatch_due_jobs(int $limit = 25): void
    {
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'cef_jobs';

        $now = gmdate('Y-m-d H:i:s');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $jobs_table
             WHERE status = 'queued' AND earliest_run_at <= %s
             ORDER BY earliest_run_at ASC, id ASC
             LIMIT %d",
            $now,
            $limit
        ));

        foreach ($ids as $id) {
            do_action(defined('CEF_CRON_RUN_JOB') ? CEF_CRON_RUN_JOB : 'cef_run_job', (int)$id);
        }
    }

    /**
     * Execute a job immediately, bypassing conditions.
     */
    public function run_job_force(int $job_id): void
    {
        global $wpdb;
        $jobs_table    = $wpdb->prefix . 'cef_jobs';
        $actions_table = $wpdb->prefix . 'cef_actions';

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $jobs_table WHERE id = %d LIMIT 1",
            $job_id
        ), ARRAY_A);
        if (!$job) return;

        $action_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $actions_table WHERE id = %d AND enabled = 1 LIMIT 1",
            $job['action_id']
        ), ARRAY_A);
        if (!$action_row) return;

        $action_def = $this->actions->get($action_row['type']);
        if (!$action_def) return;

        $context = json_decode($job['context_json'], true) ?: [];
        $config  = json_decode($action_row['config_json'], true) ?: [];

        try {
            $result = call_user_func($action_def['execute_cb'], $context, $config, $this->templating);
            $wpdb->update($jobs_table, [
                'status'       => 'sent',
                'last_eval_at' => gmdate('Y-m-d H:i:s'),
                'executed_at'  => gmdate('Y-m-d H:i:s'),
                'result_text'  => is_string($result) ? $result : maybe_serialize($result),
            ], ['id' => $job_id]);
        } catch (\Throwable $e) {
            $wpdb->update($jobs_table, [
                'status'       => 'failed',
                'last_eval_at' => gmdate('Y-m-d H:i:s'),
                'executed_at'  => gmdate('Y-m-d H:i:s'),
                'result_text'  => 'Error: ' . $e->getMessage(),
            ], ['id' => $job_id]);
        }
    }

    /**
     * Cron callback to run a single job (with condition evaluation + reschedule).
     */
    public function run_job(int $job_id): void
    {
        global $wpdb;
        $jobs_table    = $wpdb->prefix . 'cef_jobs';
        $actions_table = $wpdb->prefix . 'cef_actions';

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $jobs_table WHERE id = %d AND status = 'queued' LIMIT 1",
            $job_id
        ), ARRAY_A);
        if (!$job) return;

        $context = json_decode($job['context_json'], true) ?: [];

        $pass = $this->engine->evaluate('action', (int)$job['action_id'], $context);

        if (!$pass) {
            // Backoff: requeue up to max attempts, then skip
            $attempts     = ((int)$job['attempts']) + 1;
            $max_attempts = 48; // ~24 hours if 30-min backoff

            if ($attempts < $max_attempts) {
                $wpdb->update($jobs_table, [
                    'attempts'        => $attempts,
                    'last_eval_at'    => gmdate('Y-m-d H:i:s'),
                    'earliest_run_at' => gmdate('Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS),
                ], ['id' => $job_id]);
            } else {
                $wpdb->update($jobs_table, [
                    'status'       => 'skipped',
                    'attempts'     => $attempts,
                    'last_eval_at' => gmdate('Y-m-d H:i:s'),
                    'result_text'  => 'Skipped after max attempts.',
                ], ['id' => $job_id]);
            }
            return;
        }

        // Execute
        $action_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $actions_table WHERE id = %d AND enabled = 1 LIMIT 1",
            $job['action_id']
        ), ARRAY_A);
        if (!$action_row) return;

        $action_def = $this->actions->get($action_row['type']);
        if (!$action_def) return;

        $config = json_decode($action_row['config_json'], true) ?: [];

        $result_text = '';
        try {
            $result = call_user_func($action_def['execute_cb'], $context, $config, $this->templating);
            $result_text = is_string($result) ? $result : maybe_serialize($result);
            $status = 'sent';
        } catch (\Throwable $e) {
            $result_text = 'Error: ' . $e->getMessage();
            $status = 'failed';
        }

        $wpdb->update($jobs_table, [
            'status'       => $status,
            'last_eval_at' => gmdate('Y-m-d H:i:s'),
            'executed_at'  => gmdate('Y-m-d H:i:s'),
            'result_text'  => $result_text,
        ], ['id' => $job_id]);
    }
}