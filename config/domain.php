<?php

return [
    'pc' => [
        'default_code_prefix' => 'PC-',
        'online_statuses' => ['online', 'busy', 'reserved', 'locked', 'maintenance'],
        'online_window_minutes' => 3,
        'lock_command_recent_window_minutes' => 2,
        'offline_timeout_seconds' => 30,
        'pair_code' => [
            'default_ttl_minutes' => 10,
            'min_ttl_minutes' => 1,
            'max_ttl_minutes' => 120,
        ],
    ],
    'agent' => [
        'poll_interval_seconds' => 3,
        'device_token_ttl_hours' => 24 * 30,
        'device_token_rotate_before_hours' => 24,
    ],
    'auth' => [
        'client_token_ttl_hours' => 12,
        'club_switch_token_ttl_hours' => 24,
    ],
    'tenant' => [
        'join_code' => [
            'length' => 10,
            'ttl_days' => 30,
        ],
    ],
    'payments' => [
        'methods' => ['cash', 'card', 'balance'],
        'promotion_methods' => ['cash'],
    ],
    'nexora' => [
        'autopilot' => [
            'enabled' => false,
            'auto_lock_idle_online' => false,
            'suggest_idle_shutdown' => true,
            'suggest_offline_watch' => true,
        ],
        'watch' => [
            'idle_alert_count' => 3,
            'offline_alert_count' => 1,
            'low_load_ratio' => 0.25,
        ],
    ],
];
