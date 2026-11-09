<?php

return [
    'disk' => env('UPLOAD_DISK', 'local'),
    'chunk_size' => (int) env('UPLOAD_CHUNK_SIZE', 8 * 1024 * 1024),
    'max_size' => (int) env('UPLOAD_MAX_SIZE', 10 * 1024 * 1024 * 1024),
    'expires_hours' => (int) env('UPLOAD_EXPIRES_HOURS', 24),
];
