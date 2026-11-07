<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Actions\SendOperationalAlert;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AlertFailedIntegrations extends Command
{
    protected $signature = 'operations:alert-failures';

    protected $description = 'Email kegagalan integrasi baru (pengiriman dan job antrean)';

    public function handle(SendOperationalAlert $send): int
    {
        $candidates = array_merge($this->failedDeliveries(), $this->failedJobs());

        if ($candidates === []) {
            $this->info('Tidak ada kegagalan integrasi baru.');

            return self::SUCCESS;
        }

        $sent = $send(
            'Peringatan kegagalan integrasi',
            'Integrasi gagal',
            array_column($candidates, 'line'),
        );

        // Dedup hanya ditandai setelah email benar-benar terkirim, supaya
        // kegagalan provider tidak membuat alert hilang selamanya.
        if ($sent > 0) {
            foreach ($candidates as $candidate) {
                Cache::add($candidate['key'], true, now()->addDays(30));
            }
        }

        $this->info("{$sent} email alert integrasi terkirim.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{key: string, line: string}>
     */
    private function failedDeliveries(): array
    {
        $candidates = [];

        Order::query()
            ->where('delivery_status', 'failed')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->orderBy('id')
            ->get(['id', 'order_number'])
            ->each(function (Order $order) use (&$candidates): void {
                $key = 'operations-alert:delivery:'.$order->id;

                if (! Cache::has($key)) {
                    $candidates[] = [
                        'key' => $key,
                        'line' => "Pengiriman gagal: {$order->order_number}",
                    ];
                }
            });

        return $candidates;
    }

    /**
     * Hanya job gagal dalam 24 jam terakhir yang dianggap "baru".
     *
     * @return list<array{key: string, line: string}>
     */
    private function failedJobs(): array
    {
        $candidates = [];

        DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->orderBy('id')
            ->limit(50)
            ->get(['uuid', 'payload'])
            ->each(function (object $job) use (&$candidates): void {
                $key = 'operations-alert:job:'.$job->uuid;

                if (Cache::has($key)) {
                    return;
                }

                $payload = json_decode((string) $job->payload, true);
                $name = is_array($payload) ? (string) ($payload['displayName'] ?? 'Job') : 'Job';

                $candidates[] = [
                    'key' => $key,
                    'line' => 'Job antrean gagal: '.class_basename($name),
                ];
            });

        return $candidates;
    }
}
