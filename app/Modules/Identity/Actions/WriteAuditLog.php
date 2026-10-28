<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class WriteAuditLog
{
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
        $blocked = [
            'password',
            'password_confirmation',
            'remember_token',
            'token',
            'secret',
            'api_key',
        ];

        $safe = [];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->safe($value) : $value;
        }

        return $safe;
    }
}
