<?php

declare(strict_types=1);

// Silence deprecation notices in PHP 8.5 on serverless runtime
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Ensure Laravel resolves routes from root '/' instead of stripping '/api'
$_SERVER['SCRIPT_NAME'] = '/index.php';

// Ensure writable /tmp storage structure exists on Vercel's read-only serverless filesystem
$isVercel = ! empty($_ENV['VERCEL'] ?? $_SERVER['VERCEL'] ?? getenv('VERCEL'));
if ($isVercel) {
    $tmpDirs = [
        '/tmp/storage',
        '/tmp/storage/app',
        '/tmp/storage/framework',
        '/tmp/storage/framework/cache',
        '/tmp/storage/framework/cache/data',
        '/tmp/storage/framework/sessions',
        '/tmp/storage/framework/views',
        '/tmp/storage/fonts',
        '/tmp/storage/logs',
    ];

    foreach ($tmpDirs as $dir) {
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}

require __DIR__ . '/../public/index.php';
