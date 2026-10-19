<?php

return [
    // Written by `php artisan calmify:install` (never share it).
    'api_key' => env('CALMIFY_API_KEY'),
    // Written by `php artisan calmify:pair`: the only host covers are downloaded from.
    'asset_host' => env('CALMIFY_ASSET_HOST'),
    // Calmify's address (for pairing).
    'calmify_url' => env('CALMIFY_URL', 'https://joincalmify.com'),
    // Where Calmify sends posts on this site.
    'route' => env('CALMIFY_ROUTE', 'api/calmify/posts'),
    // Table posts are saved to (created by the package migration). To use
    // your own posts model instead, listen for Calmify\Laravel\Events\PostReceived.
    'table' => env('CALMIFY_TABLE', 'calmify_posts'),
    // Public URL of a post; {slug} is replaced.
    'post_url' => env('CALMIFY_POST_URL', rtrim((string) env('APP_URL', ''), '/').'/blog/{slug}'),
    // Public disk folder for cover images.
    'image_disk' => env('CALMIFY_IMAGE_DISK', 'public'),
    'image_dir' => 'calmify',
];
