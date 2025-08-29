<?php
// File: vendor/Cef/Registry/Actions/EmailAction.php

namespace Cef\Registry\Actions;

use Cef\Registry\ActionRegistry;
use Cef\Templating\Templating;

defined('ABSPATH') || exit;

class EmailAction
{
    /**
     * Register this action type with the registry.
     */
    public static function register(ActionRegistry $registry): void
    {
        $registry->register([
            'key'         => 'email',
            'label'       => __('Send Email', 'cef'),
            'execute_cb'  => [self::class, 'execute'],
            'config_schema' => [
                'to'      => ['type' => 'text', 'label' => __('To (comma-separated)', 'cef')],
                'subject' => ['type' => 'text', 'label' => __('Subject', 'cef')],
                'body'    => ['type' => 'wysiwyg', 'label' => __('Body (HTML allowed)', 'cef')],
            ],
        ]);
    }

    /**
     * Execute the email action.
     *
     * @param array       $context    Event context
     * @param array       $config     Action config from DB
     * @param Templating  $templating Templating helper
     * @return string     Result text for logging
     */
    public static function execute(array $context, array $config, Templating $templating): string
    {
        $to_raw      = $config['to']      ?? '';
        $subject_tpl = $config['subject'] ?? '';
        $body_tpl    = $config['body']    ?? '';
        $headers_raw = $config['headers'] ?? '';

        // Render templates
        $subject = $templating->render($subject_tpl, $context);
        $body    = $templating->render($body_tpl, $context);

        // Resolve recipients (allow placeholders in "to" as well)
        $to_rendered = $templating->render($to_raw, $context);
        $recipients  = $to_rendered ? array_filter(array_map('trim', explode(',', $to_rendered))) : [];

        if (empty($recipients)) {
            throw new \RuntimeException('No recipients resolved for email action.');
        }

        // Headers
        $headers = [];
        if (!empty($headers_raw)) {
            $headers_lines = preg_split('/\r\n|\r|\n/', trim($headers_raw));
            foreach ($headers_lines as $line) {
                if (strpos($line, ':') !== false) {
                    $headers[] = trim($line);
                }
            }
        }

        // Send email
        $sent = wp_mail($recipients, $subject, $body, $headers);

        if (!$sent) {
            throw new \RuntimeException('wp_mail() returned false.');
        }

        return sprintf(
            'Email sent to %s with subject "%s"',
            implode(', ', $recipients),
            $subject
        );
    }
}