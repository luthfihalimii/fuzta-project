<?php

return [
    'enabled' => env('BACKUP_ENABLED', false),
    'time' => env('BACKUP_TIME', '02:00'),
    'directory' => env('BACKUP_DIR', '/var/backups/pizza-fruzta'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    's3_bucket' => env('BACKUP_S3_BUCKET'),
    's3_prefix' => env('BACKUP_S3_PREFIX', 'pizza-fruzta'),
];
