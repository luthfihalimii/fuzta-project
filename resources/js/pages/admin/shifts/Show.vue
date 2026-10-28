<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Money from '@/components/Money.vue';
import CashMovementForm from '@/components/shifts/CashMovementForm.vue';
import CloseShiftDialog from '@/components/shifts/CloseShiftDialog.vue';
import ReconcileShiftDialog from '@/components/shifts/ReconcileShiftDialog.vue';
import ShiftSummary from '@/components/shifts/ShiftSummary.vue';
import ShiftStatusBadge from '@/components/shifts/ShiftStatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import type { ShiftSummaryData } from '@/lib/shift';

const props = defineProps<{
    shift: ShiftSummaryData;
    summary: Record<string, unknown>;
    totals: Record<string, number>;
    movements: Array<{
        id: number;
        direction: string;
        amount: number;
        reason: string;
        actor_name: string | null;
        created_at: string | null;
    }>;
    orders: Array<{
        id: number;
        order_number: string;
        total: number;
        status: string;
        payment_status: string;
        created_at: string | null;
    }>;
}>();

const closeOpen = ref(false);
</script>

<template>
    <Head :title="`Shift ${props.shift.window_key}`" />
    <StaffLayout>
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold text-ink">
                            Shift {{ props.shift.window_key }}
                        </h1>
                        <ShiftStatusBadge :shift="props.shift" />
                    </div>
                    <p class="mt-1 text-sm text-muted">
                        {{ props.shift.user_name ?? '—' }} · buka
                        {{ formatDateTime(props.shift.opened_at) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/shifts"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink"
                        >Kembali</Link
                    >
                    <button
                        v-if="props.shift.status === 'open'"
                        type="button"
                        class="inline-flex min-h-12 items-center rounded-xl bg-brand px-4 text-sm font-semibold text-white"
                        @click="closeOpen = true"
                    >
                        Tutup shift
                    </button>
                </div>
            </div>

            <ShiftSummary :shift="props.shift" />

            <ReconcileShiftDialog
                v-if="props.shift.status === 'auto_closed_unreconciled'"
                :shift="props.shift"
            />

            <CashMovementForm
                v-if="props.shift.status === 'open'"
                :shift-id="props.shift.id"
            />

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Kas masuk/keluar</h2>
                </div>
                <div
                    v-if="props.movements.length === 0"
                    class="p-5 text-sm text-muted"
                >
                    Belum ada pergerakan kas.
                </div>
                <div v-else class="divide-y divide-line">
                    <div
                        v-for="movement in props.movements"
                        :key="movement.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                    >
                        <div>
                            <p class="font-semibold text-ink">
                                {{
                                    movement.direction === 'in'
                                        ? 'Masuk'
                                        : 'Keluar'
                                }}
                                · <Money :value="movement.amount" />
                            </p>
                            <p class="text-muted">
                                {{ movement.reason }} ·
                                {{ formatDateTime(movement.created_at) }}
                            </p>
                        </div>
                        <span class="text-muted">{{
                            movement.actor_name ?? 'Sistem'
                        }}</span>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex items-center justify-between border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Pesanan shift</h2>
                    <Link
                        :href="`/pos/orders?branch=${props.shift.branch_id}&shift=${props.shift.id}`"
                        class="text-sm font-semibold text-brand underline"
                        >Lihat pesanan</Link
                    >
                </div>
                <div
                    v-if="props.orders.length === 0"
                    class="p-5 text-sm text-muted"
                >
                    Belum ada pesanan.
                </div>
                <div v-else class="divide-y divide-line">
                    <div
                        v-for="order in props.orders"
                        :key="order.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                    >
                        <div>
                            <p class="font-semibold text-ink">
                                {{ order.order_number }}
                            </p>
                            <p class="text-muted">
                                {{ formatDateTime(order.created_at) }} ·
                                {{ order.payment_status }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Badge
                                :tone="
                                    order.payment_status === 'paid'
                                        ? 'success'
                                        : 'warning'
                                "
                                >{{ order.payment_status }}</Badge
                            >
                            <Money :value="order.total" />
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <CloseShiftDialog
            :open="closeOpen"
            :shift="props.shift"
            :endpoint="`/admin/shifts/${props.shift.id}/close`"
            @close="closeOpen = false"
        />
    </StaffLayout>
</template>
