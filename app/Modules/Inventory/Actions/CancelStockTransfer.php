<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelStockTransfer
{
    public function __construct(
        private readonly WriteAuditLog $writeAuditLog,
    ) {}

    public function __invoke(User $actor, int $transferId, string $reason): StockTransfer
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($actor, $transferId, $reason): StockTransfer {
            $transfer = StockTransfer::query()
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== TransferStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transfer draft yang dapat dibatalkan.',
                ]);
            }

            $transfer->update([
                'status' => TransferStatus::Cancelled,
                'cancel_reason' => $reason,
            ]);

            ($this->writeAuditLog)(
                $actor,
                'transfer.cancelled',
                $transfer,
                ['status' => TransferStatus::Draft->value],
                ['status' => TransferStatus::Cancelled->value],
                $reason,
            );

            return $transfer;
        });
    }
}
