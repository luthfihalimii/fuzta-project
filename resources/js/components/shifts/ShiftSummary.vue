<script setup lang="ts">
import Money from '@/components/Money.vue';
import { formatDateTime } from '@/lib/datetime';
import type { ShiftSummaryData } from '@/lib/shift';

defineProps<{ shift: ShiftSummaryData }>();
</script>

<template>
    <section class="rounded-2xl border border-line bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-ink">
                    Ringkasan shift {{ shift.window_key }}
                </h2>
                <p class="mt-0.5 text-sm text-muted">
                    Buka {{ formatDateTime(shift.opened_at) }}
                </p>
            </div>
            <div class="text-right text-sm text-muted">
                <p>Kas awal</p>
                <p class="font-semibold text-ink">
                    <Money :value="shift.opening_cash" />
                </p>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div class="flex justify-between gap-2">
                <dt class="text-muted">Tunai</dt>
                <dd class="font-semibold text-ink">
                    <Money :value="shift.cash_sales_total ?? 0" />
                </dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-muted">QRIS</dt>
                <dd class="font-semibold text-ink">
                    <Money :value="shift.qris_sales_total ?? 0" />
                </dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-muted">Kas masuk</dt>
                <dd class="font-semibold text-ink">
                    <Money :value="shift.cash_in_total ?? 0" />
                </dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-muted">Kas keluar</dt>
                <dd class="font-semibold text-ink">
                    <Money :value="shift.cash_out_total ?? 0" />
                </dd>
            </div>
        </dl>

        <div
            class="mt-4 flex items-center justify-between border-t border-line pt-3"
        >
            <span class="text-sm font-semibold text-ink">Kas seharusnya</span>
            <span class="text-lg font-bold text-ink">
                <Money :value="shift.expected_cash ?? shift.opening_cash" />
            </span>
        </div>

        <div v-if="shift.counted_cash !== null" class="mt-3 space-y-1 text-sm">
            <div class="flex justify-between">
                <span class="text-muted">Kas dihitung</span>
                <span class="font-semibold text-ink">
                    <Money :value="shift.counted_cash" />
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted">Selisih</span>
                <span
                    class="font-semibold"
                    :class="
                        (shift.variance ?? 0) === 0
                            ? 'text-success'
                            : 'text-danger'
                    "
                >
                    <Money :value="shift.variance ?? 0" />
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted">Setoran</span>
                <span class="font-semibold text-ink">
                    <Money :value="shift.cash_deposited ?? 0" />
                </span>
            </div>
        </div>
    </section>
</template>
