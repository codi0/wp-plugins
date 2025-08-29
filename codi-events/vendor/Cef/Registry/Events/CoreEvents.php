<?php
// File: vendor/Cef/Registry/Events/CoreEvents.php

namespace Cef\Registry\Events;

use Cef\Registry\EventRegistry;

defined('ABSPATH') || exit;

/**
 * Registers a set of common WordPress core events with sensible context payloads.
 */
class CoreEvents
{
    public static function register_all(EventRegistry $registry): void
    {
        $events = [
            'add_user_to_blog' => [
                'label'       => __('User added to site', 'cef'),
                'description' => __('Triggered after a new user is added to a site.', 'cef'),
                'callback'     => function ($user_id) {
                    return [
                        'context'    => [
                            'user_id'    => $user_id,
                        ],
                        'dedupe_key' => (string) $user_id,
                    ];
                },
            ],
            'wp_login' => [
                'label'       => __('User logs in', 'cef'),
                'description' => __('Triggered after a user logs in.', 'cef'),
                'callback'     => function ($user_login, $user) {
                    return [
                        'context'    => [
                            'user_id'    => $user->ID,
                            'user_login' => $user_login,
                        ],
                        'dedupe_key' => (string) $user->ID,
                    ];
                },
            ],
            'profile_update' => [
                'label'       => __('User profile updated', 'cef'),
                'description' => __('Triggered after a user profile is updated.', 'cef'),
                'callback'     => function ($user_id, $old_user_data) {
                    $user = get_userdata($user_id);
                    return [
                        'context'    => [
                            'user_id'       => $user_id,
                            'old_user_data' => (array) $old_user_data,
                        ],
                        'dedupe_key' => (string) $user_id,
                    ];
                },
            ],
            'remove_user_from_blog' => [
                'label'       => __('User removed from site', 'cef'),
                'description' => __('Triggered after a user is removed from a site.', 'cef'),
                'callback'     => function ($user_id) {
                    return [
                        'context'    => [
							'user_id' => $user_id,
						],
                        'dedupe_key' => (string) $user_id,
                    ];
                },
            ],
            'publish_post' => [
                'label'       => __('Post published', 'cef'),
                'description' => __('Triggered when a post is published.', 'cef'),
                'callback'     => function ($post_id, $post) {
                    return [
                        'context'    => [
                            'post_id'    => $post_id,
                        ],
                        'dedupe_key' => (string) $post_id,
                    ];
                },
            ],
            'comment_post' => [
                'label'       => __('Comment added', 'cef'),
                'description' => __('Triggered when a comment is posted.', 'cef'),
                'callback'     => function ($comment_ID, $comment_approved, $commentdata) {
                    return [
                        'context'    => [
                            'comment_id'      => $comment_ID,
                        ],
                        'dedupe_key' => (string) $comment_ID,
                    ];
                },
            ],
        ];

        foreach ($events as $key => $def) {
			$def['key'] = $key;
            $registry->register($def);
        }
    }
}