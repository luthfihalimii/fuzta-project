<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Validation\ValidationException;

class ConvertUnit
{
    /**
     * Convert an integer quantity between units along the shortest conversion path.
     *
     * Stored edges are directed, but are traversed in reverse when needed
     * (1 kg = 1000 g also allows 1000 g = 1 kg).
     */
    public function __invoke(int $fromUnitId, int $quantity, int $toUnitId): int
    {
        if ($fromUnitId === $toUnitId) {
            return $quantity;
        }

        $edges = UnitConversion::query()
            ->get(['from_unit_id', 'to_unit_id', 'from_quantity', 'to_quantity'])
            ->map(fn (UnitConversion $edge): array => [
                (int) $edge->from_unit_id,
                (int) $edge->to_unit_id,
                (int) $edge->from_quantity,
                (int) $edge->to_quantity,
            ]);

        // BFS: state = [unit id, quantity converted so far]. Division is integer, so the
        // quantity must travel with the search; each unit is reached by at most one path.
        $queue = [[$fromUnitId, $quantity]];
        $visited = [$fromUnitId => true];

        while ($queue !== []) {
            [$unitId, $result] = array_shift($queue);

            if ($unitId === $toUnitId) {
                return $result;
            }

            foreach ($edges as [$edgeFrom, $edgeTo, $edgeFromQty, $edgeToQty]) {
                // shortcut: floor rounding on inexact results; upgrade when lots/expiry need fractional qty.
                if ($edgeFrom === $unitId) {
                    $next = intdiv($result * $edgeToQty, $edgeFromQty);
                    $nextUnitId = $edgeTo;
                } elseif ($edgeTo === $unitId) {
                    $next = intdiv($result * $edgeFromQty, $edgeToQty);
                    $nextUnitId = $edgeFrom;
                } else {
                    continue;
                }

                if (isset($visited[$nextUnitId])) {
                    continue;
                }

                $visited[$nextUnitId] = true;
                $queue[] = [$nextUnitId, $next];
            }
        }

        throw ValidationException::withMessages([
            'unit' => 'Unit tidak dapat dikonversi.',
        ]);
    }
}
