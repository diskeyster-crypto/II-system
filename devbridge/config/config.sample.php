<?php
// DevBridge Configuration — copy to config.php and fill in values
return [
    'app_url'         => 'https://example.com',   // no trailing slash; set your domain/subfolder
    'db_host'         => '127.0.0.1',
    'db_port'         => '3306',
    'db_name'         => 'devbridge',
    'db_user'         => 'devbridge',
    'db_pass'         => '',
    'app_secret'      => '',   // random 32-byte hex used for encryption & CSRF
    'installed'       => true,
    'locale'          => 'ru',           // interface language: ru or en
    'fallback_locale' => 'en',

    // AI provider configuration — stored in DB settings table after install.
    // These are only used as defaults during fresh install.
    'ai' => [
        'provider'        => 'gemini',   // gemini (default) | openrouter (legacy)
        'default_profile' => 'balanced', // economy | balanced | strong | manual
        'gemini_api_key'  => '',          // add key in Settings after install
        'models'          => [
            'economy'     => 'gemini-2.5-flash-lite',
            'balanced'    => 'gemini-2.5-flash',
            'strong'      => 'gemini-2.5-pro',
            'json_repair' => 'gemini-2.5-flash-lite',
        ],
        'temperatures'    => [
            'planner'       => 0.4,
            'critic'        => 0.2,
            'prompt_builder'=> 0.2,
            'reviewer'      => 0.1,
            'json_repair'   => 0.0,
        ],
        'max_output_tokens' => 8192,
    ],
];
