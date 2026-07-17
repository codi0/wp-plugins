<?php

defined('ABSPATH') || exit;

final class CES_Email_Dispatcher {
    /**
     * Runs the same validation, rendering, recipient, and delivery pipeline used by scheduled actions.
     *
     * @return array|WP_Error
     */
    /**
     * Evaluate whether an email is currently eligible and resolve recipients without rendering or sending.
     *
     * @return array|WP_Error
     */
    public static function evaluate_eligibility(array $email, array $context, array $runtime = [], array $options = []) {
        $event_key = sanitize_key((string) ($email['event_key'] ?? ''));
        $event = $event_key !== '' ? CES_Registry::event($event_key) : null;
        if (!$event) {
            return new WP_Error('event_definition_missing', __('The event definition is unavailable, so the email was not sent.', 'codi-email-scheduler'), [
                'runtime_failure' => [
                    'reason' => 'event_definition_missing',
                    'message' => __('The event definition is unavailable.', 'codi-email-scheduler'),
                    'source' => 'event_definition',
                    'details' => [],
                ],
            ]);
        }

        $validated_email = CES_Email_Validator::validate($email);
        if (is_wp_error($validated_email)) {
            return $validated_email;
        }
        $email = $validated_email;

        $conditions = CES_Engine::evaluate_conditions($email, $context, $runtime);
        if (empty($conditions['passed'])) {
            return self::validation_error(
                'conditions_failed',
                __('Email conditions did not match or could not be evaluated safely.', 'codi-email-scheduler'),
                ['condition_results' => $conditions['results'] ?? []],
                $conditions['failure'] ?? []
            );
        }

        if (!(bool) apply_filters('ces_can_send_email', true, $context, $email, $runtime)) {
            return new WP_Error('sending_not_allowed', __('Email sending was rejected by the ces_can_send_email filter.', 'codi-email-scheduler'));
        }

        if (array_key_exists('recipient_override', $options)) {
            $recipients = self::normalize_recipients($options['recipient_override']);
            if (!$recipients) {
                return new WP_Error('invalid_recipient_override', __('The test recipient is invalid.', 'codi-email-scheduler'));
            }
        } else {
            $recipients = CES_Engine::resolve_recipients($email, $context, $runtime);
            if (is_wp_error($recipients)) {
                return self::validation_error(
                    'no_recipients',
                    __('No valid recipients were resolved.', 'codi-email-scheduler'),
                    [],
                    [
                        'reason'  => $recipients->get_error_code(),
                        'message' => $recipients->get_error_message(),
                        'source'  => 'recipient_callback',
                        'details' => ['error_data' => $recipients->get_error_data()],
                    ]
                );
            }
            if (!$recipients) {
                return self::validation_error('no_recipients', __('No valid recipients were resolved.', 'codi-email-scheduler'));
            }
        }

        return [
            'email' => $email,
            'event' => $event,
            'recipients' => $recipients,
        ];
    }

    /**
     * Runs the same validation, rendering, recipient, and delivery pipeline used by scheduled actions.
     *
     * @return array|WP_Error
     */
    public static function dispatch(array $email, array $context, array $runtime = [], array $options = []) {
        $eligibility = self::evaluate_eligibility($email, $context, $runtime, $options);
        if (is_wp_error($eligibility)) {
            return $eligibility;
        }
        $email = $eligibility['email'];
        $recipients = $eligibility['recipients'];

        $subject = CES_Engine::replace_tokens((string) ($email['subject'] ?? ''), $context, $email, array_merge($runtime, ['token_context' => 'subject']));
        if (is_wp_error($subject)) {
            return self::runtime_error($subject, 'token_callback');
        }
        $subject = apply_filters('ces_email_subject', $subject, $email, $context, $runtime);

        $body = CES_Engine::replace_tokens((string) ($email['body'] ?? ''), $context, $email, array_merge($runtime, ['token_context' => 'body']));
        if (is_wp_error($body)) {
            return self::runtime_error($body, 'token_callback');
        }
        $body = apply_filters('ces_email_body', $body, $email, $context, $runtime);
        $headers = apply_filters('ces_email_headers', ['Content-Type: text/html; charset=UTF-8'], $email, $context, $runtime);
        $attachments = apply_filters('ces_email_attachments', [], $email, $context, $runtime);
        $sent = [];
        $failed = [];

        foreach ($recipients as $recipient) {
            $to = sanitize_email((string) $recipient);
            if (!is_email($to)) {
                $failed[] = (string) $recipient;
                continue;
            }

            if (wp_mail($to, (string) $subject, (string) $body, $headers, $attachments)) {
                $sent[] = $to;
                do_action('ces_email_sent', $to, $email, $context, $runtime);
            } else {
                $failed[] = $to;
            }
        }

        $status = $sent && !$failed ? 'sent' : ($sent ? 'partially_sent' : 'failed');
        $reason = $failed ? 'mail_failed' : '';
        if ($status === 'sent') {
            $message = __('Email sent.', 'codi-email-scheduler');
        } elseif ($status === 'partially_sent') {
            $message = sprintf(__('Email partially sent. Failed for: %s', 'codi-email-scheduler'), implode(', ', array_map('strval', $failed)));
        } else {
            $message = sprintf(__('Email failed for: %s', 'codi-email-scheduler'), implode(', ', array_map('strval', $failed)));
        }

        if ($failed) {
            do_action('ces_email_failed', $failed, $email, $context, $runtime);
        }

        return [
            'status'     => $status,
            'reason'     => $reason,
            'message'    => $message,
            'subject'    => (string) $subject,
            'body'       => (string) $body,
            'recipients' => $recipients,
            'sent'       => $sent,
            'failed'     => $failed,
        ];
    }

    private static function runtime_error(WP_Error $error, string $source): WP_Error {
        return new WP_Error($error->get_error_code(), $error->get_error_message(), [
            'runtime_failure' => [
                'reason'  => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'source'  => sanitize_key($source),
                'details' => [
                    'source'     => sanitize_key($source),
                    'error_data' => $error->get_error_data(),
                ],
            ],
        ]);
    }

    private static function validation_error(string $code, string $message, array $data = [], array $failure = []): WP_Error {
        if (!empty($failure['reason'])) {
            $code = sanitize_key((string) $failure['reason']);
        }
        if (!empty($failure['message'])) {
            $message = sanitize_text_field((string) $failure['message']);
        }
        if ($failure) {
            $data['runtime_failure'] = $failure;
        }

        return new WP_Error($code, $message, $data);
    }

    private static function normalize_recipients($raw): array {
        $raw = is_array($raw) ? $raw : [$raw];
        $recipients = [];
        foreach ($raw as $recipient) {
            $email = sanitize_email((string) $recipient);
            if ($email !== '' && is_email($email)) {
                $recipients[] = $email;
            }
        }
        return array_values(array_unique($recipients));
    }
}
