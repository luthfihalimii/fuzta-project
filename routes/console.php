<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('backup:database', function () {
    $database = config('database.connections.pgsql');

    $process = new Process(
        [base_path('scripts/backup-database.sh')],
        env: array_merge((array) getenv(), [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/var/www',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'BACKUP_DIR' => (string) config('backup.directory'),
            'BACKUP_RETENTION_DAYS' => (string) config('backup.retention_days'),
            'BACKUP_S3_BUCKET' => (string) config('backup.s3_bucket'),
            'BACKUP_S3_PREFIX' => (string) config('backup.s3_prefix'),
        ]),
    );

    $process->setTimeout(3600);
    $process->mustRun(function (string $type, string $buffer): void {
        $this->output->write($buffer);
    });

    return 0;
})->purpose('Write and verify a PostgreSQL backup');

Schedule::command('shifts:auto-close')
    ->everyFiveMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

if (config('backup.enabled')) {
    Schedule::command('backup:database')
        ->dailyAt(config('backup.time'))
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping();
}
