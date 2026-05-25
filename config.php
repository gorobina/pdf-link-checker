<?php

declare(strict_types=1);

return [
    'app_name' => 'PDF Link Checker',

    'paths' => [
        'base_dir' => __DIR__,
        'jobs_dir' => __DIR__ . '/jobs',
        'python_bin' => __DIR__ . '/venv/bin/python3',
        'python_script' => __DIR__ . '/python/pdf_link_checker.py',
    ],

    'limits' => [
        'max_pdf_size_bytes' => 500 * 1024 * 1024, // 500 MB
        'max_links' => 500,
        'timeout' => 5,
        'follow_redirects' => false,
        'http_method' => 'HEAD',
    ],

    'security' => [
        'allowed_url_schemes' => ['http', 'https'],
    ],
];