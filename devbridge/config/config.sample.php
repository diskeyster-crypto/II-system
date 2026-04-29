<?php
// DevBridge Configuration - copy to config.php and fill in values
return [
    'app_url'         => 'https://example.com',   // no trailing slash; set your domain/subfolder
    'db_host'         => '127.0.0.1',
    'db_port'         => '3306',
    'db_name'         => 'devbridge',
    'db_user'         => 'devbridge',
    'db_pass'         => '',
    'app_secret'      => '',   // random 32-byte hex used for encryption & CSRF
    'installed'       => true,
    'locale'          => 'ru',          // interface language: ru or en
    'fallback_locale' => 'en',
];
