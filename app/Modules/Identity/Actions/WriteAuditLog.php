<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class WriteAuditLog
{
    /**
     * Cocokkan substring, bukan exact key: variasi seperti access_token,
     * customer_phone, atau client_secret tetap teredaksi.
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'customer_phone',
        'customer_address',
        'delivery_address',
        'recipient_phone',
        'card_number',
        'cardholder',
        'cvv',
        'cvc',
        'account_number',
        'iban',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __invoke(
        ?User $actor,
        string $action,
        Model $target,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): AuditLog {
        $request = $actor === null ? null : request();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'branch_id' => $target->getAttribute('branch_id') ?? $actor?->branch_id,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'before' => $this->safe($before),
            'after' => $this->safe($after),
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safe(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $keyName = strtolower((string) $key);

            foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
                if (str_contains($keyName, $pattern)) {
                    continue 2;
                }
            }

            $safe[$key] = is_array($value) ? $this->safe($value) : $value;
        }

        return $safe;
    }
}
