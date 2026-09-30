<?php

return [
    // Default timezone for new channels' posting times.
    'timezone' => env('CHANNELS_TIMEZONE', 'America/New_York'),

    // How far ahead of a post time the video is made, so there is time to retry.
    'lead_minutes' => (int) env('CHANNELS_LEAD_MINUTES', 120),

    // How often trends are re-collected.
    'trend_refresh_minutes' => (int) env('CHANNELS_TREND_REFRESH_MINUTES', 60),

    'writer' => [
        // claude_cli: Claude Code on this computer (uses your Claude subscription).
        // anthropic: the Claude API with ANTHROPIC_API_KEY. template: no AI, for tests.
        'driver' => env('CHANNELS_WRITER', 'claude_cli'),
        'claude_bin' => env('CLAUDE_BIN', 'claude'),
        'model' => env('CHANNELS_WRITER_MODEL', 'claude-sonnet-5-5'),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'timeout' => (int) env('CHANNELS_WRITER_TIMEOUT', 300),
    ],

    'media' => [
        'engine_path' => env('MEDIA_ENGINE_PATH', base_path('media-engine')),
        'node_bin' => env('NODE_BIN', 'node'),
        'chromium' => env('SVM_CHROMIUM'),
        'timeout' => (int) env('MEDIA_RENDER_TIMEOUT', 1200),
    ],

    'facebook' => [
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
    ],

    'http' => [
        'user_agent' => env('CHANNELS_USER_AGENT', 'ChannelManager/0.1 (+https://github.com/nfrienddeveloper/channel-manager)'),
    ],
];
