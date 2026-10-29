<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { realtimeEcho } from '@/bootstrap/echo';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import PaymentSheet from '@/components/pos/PaymentSheet.vue';
import ReceiptPreview from '@/components/pos/ReceiptPreview.vue';
import ShiftStatusBadge from '@/components/shifts/ShiftStatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import type {
    BranchOption,
    BranchSummary,
    OrderRow,
    PosOrder,
} from '@/lib/order';
import {
    channelLabel,
    orderStatusLabel,
    orderStatusTone,
    paymentStatusLabel,
    paymentStatusTone,
} from '@/lib/status';
import type { ShiftSummaryData } from '@/lib/shift';

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    orders: OrderRow[];
    today: string;
    posUrl: string;
    shift: ShiftSummaryData | null;
    orders_cutoff_at: string | null;
    can_create_orders: boolean;
}>();

const page = usePage();

const branchSelect = ref<number | null>(
    props.branch?.id ?? props.branches[0]?.id ?? null,
);
const payingOrder = ref<OrderRow | null>(null);
const paymentOpen = ref(false);
const receiptOrder = ref<PosOrder | null>(null);
const receiptOpen = ref(false);
const reasonAction = ref<'cancel' | 'void' | null>(null);
const reasonOrder = ref<OrderRow | null>(null);
const reason = ref('');
const processing = ref(false);
const reasonError = ref('');

let realtimeChannel: string | null = null;
let unbindConnection: (() => void) | null = null;
let refreshTimer: number | undefined;
let refreshPending = false;

const todayLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'full',
        timeZone: 'UTC',
    }).format(new Date(`${props.today}T00:00:00Z`)),
);

function openPay(order: OrderRow): void {
    payingOrder.value = order;
    paymentOpen.value = true;
}

function openReceipt(order: PosOrder): void {
    receiptOrder.value = order;
    receiptOpen.value = true;
}

function openReason(action: 'cancel' | 'void', order: OrderRow): void {
    reasonAction.value = action;
    reasonOrder.value = order;
    reason.value = '';
    reasonError.value = '';
}

function closeReason(): void {
    if (processing.value) {
        return;
    }

    reasonAction.value = null;
    reasonOrder.value = null;
    reason.value = '';
    reasonError.value = '';
}

function submitReason(): void {
    if (!reasonOrder.value || !reasonAction.value) {
        return;
    }

    if (reason.value.trim() === '') {
        reasonError.value = 'Alasan wajib diisi.';

        return;
    }

    const order = reasonOrder.value;
    const action = reasonAction.value;

    processing.value = true;
    reasonError.value = '';

    router.post(
        `/orders/${order.id}/${action}`,
        { reason: reason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                reasonAction.value = null;
                reasonOrder.value = null;
                reason.value = '';
            },
            onError: (errors) => {
                reasonError.value =
                    errors.reason ?? errors.order ?? 'Pesanan gagal diproses.';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function onPaid(order: PosOrder): void {
    paymentOpen.value = false;
    payingOrder.value = null;
    receiptOrder.value = order;
    receiptOpen.value = true;
    router.reload({ only: ['orders'] });
}

function switchBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/pos/orders',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true },
    );
}

function refresh(): void {
    // coalesce: burst event (dapur ubah 5 order) cukup 1 reload
    if (refreshPending) {
        return;
    }

    refreshPending = true;
    refreshTimer = window.setTimeout(() => {
        refreshPending = false;
        router.reload({ only: ['orders'] });
    }, 50);
}

function onRealtime(): void {
    refresh();
}

function connectRealtime(): void {
    const echo = realtimeEcho();
    const branch = props.branch;

    if (!echo || !branch) {
        // tanpa Reverb halaman tetap jalan (reload manual / interval kasir)
        return;
    }

    realtimeChannel = `pos.${branch.id}`;

    echo.private(realtimeChannel)
        // prefix titik wajib: broadcastAs() server memakai nama polos,
        // tanpa titik echo menambahkan namespace App\Events
        .listen('.order-created', onRealtime)
        .listen('.order-status-changed', onRealtime)
        .listen('.order-payment-changed', onRealtime);

    unbindConnection = echo.connector.onConnectionChange((status) => {
        if (status === 'connected') {
            refresh();
        }
    });
}

onMounted(() => {
    connectRealtime();
});

onUnmounted(() => {
    window.clearTimeout(refreshTimer);
    unbindConnection?.();

    if (realtimeChannel !== null) {
        realtimeEcho()?.leave(realtimeChannel);
    }
});
</script>

<template>
    <Head title="Pesanan" />

    <StaffLayout>
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Pesanan</h1>
                    <p class="mt-1 text-sm text-muted">
                        {{ todayLabel }}
                        <template v-if="props.branch">
                            · {{ props.branch.name }} ({{ props.branch.code }})
                        </template>
                    </p>
                    <div
                        v-if="props.shift"
                        class="mt-2 flex flex-wrap items-center gap-2"
                    >
                        <ShiftStatusBadge :shift="props.shift" />
                        <span class="text-xs text-muted">
                            {{ props.shift.window_key }}
                            · kas seharusnya
                            <Money :value="props.shift.expected_cash ?? 0" />
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-if="props.canChooseBranch"
                        v-model="branchSelect"
                        class="min-h-12 rounded-xl border border-line bg-white px-3 text-sm font-semibold text-ink"
                        aria-label="Pilih cabang"
                        @change="switchBranch"
                    >
                        <option
                            v-for="branch in props.branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.code }} — {{ branch.name }}
                        </option>
                    </select>

                    <Link
                        :href="props.posUrl"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand/90"
                    >
                        Kembali ke POS
                    </Link>
                </div>
            </div>

            <div
                v-if="!props.can_create_orders && props.shift"
                class="rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
            >
                Batas pesanan shift ini sudah lewat. Pembayaran masih bisa
                dilakukan sampai shift ditutup.
            </div>

            <div
                v-if="page.props.flash.success"
                class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
            >
                {{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash.error"
                class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
            >
                {{ page.props.flash.error }}
            </div>

            <EmptyState
                v-if="!props.branch"
                title="Cabang belum dipilih"
                description="Pilih cabang aktif untuk melihat pesanan hari ini."
            />

            <EmptyState
                v-else-if="props.orders.length === 0"
                title="Belum ada pesanan hari ini"
                description="Pesanan baru dari POS akan muncul di sini."
            >
                <Link
                    :href="props.posUrl"
                    class="inline-flex min-h-12 items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white"
                >
                    Buat pesanan
                </Link>
            </EmptyState>

            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="order in props.orders"
                    :key="order.id"
                    class="flex flex-col rounded-2xl border border-line bg-white p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">
                                {{ order.order_number }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted">
                                {{ formatDateTime(order.created_at) }} ·
                                {{ channelLabel(order.channel) }}
                                <template v-if="order.table_number">
                                    · Meja {{ order.table_number }}
                                </template>
                            </p>
                        </div>
                        <p class="shrink-0 text-base font-bold text-ink">
                            <Money :value="order.total" />
                        </p>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <Badge :tone="orderStatusTone(order.status)">
                            {{ orderStatusLabel(order.status) }}
                        </Badge>
                        <Badge :tone="paymentStatusTone(order.payment_status)">
                            {{ paymentStatusLabel(order.payment_status) }}
                        </Badge>
                    </div>

                    <p class="mt-2 text-sm text-muted">
                        {{ order.items.length }} item
                        <template v-if="order.customer_name">
                            · {{ order.customer_name }}
                        </template>
                    </p>
                    <p
                        v-if="order.note"
                        class="mt-1 line-clamp-2 text-xs text-muted"
                    >
                        {{ order.note }}
                    </p>

                    <div
                        class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3"
                    >
                        <Button
                            v-if="order.can.pay"
                            class="min-h-12 px-4"
                            @click="openPay(order)"
                        >
                            Bayar
                        </Button>
                        <button
                            v-if="order.can.cancel"
                            type="button"
                            class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm font-semibold text-danger transition hover:bg-danger/5"
                            @click="openReason('cancel', order)"
                        >
                            Batalkan
                        </button>
                        <button
                            v-if="order.can.void"
                            type="button"
                            class="min-h-12 rounded-xl border border-danger/30 bg-white px-4 text-sm font-semibold text-danger transition hover:bg-danger/5"
                            @click="openReason('void', order)"
                        >
                            Void
                        </button>
                        <button
                            type="button"
                            class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                            @click="openReceipt(order)"
                        >
                            Struk
                        </button>
                    </div>
                </article>
            </div>
        </div>

        <PaymentSheet
            :open="paymentOpen"
            :order="payingOrder"
            @close="paymentOpen = false"
            @paid="onPaid"
        />

        <ReceiptPreview
            :open="receiptOpen"
            :order="receiptOrder"
            @close="receiptOpen = false"
        />

        <Teleport to="body">
            <div
                v-if="reasonAction && reasonOrder"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                :aria-label="
                    reasonAction === 'void'
                        ? 'Void pesanan'
                        : 'Batalkan pesanan'
                "
                @click.self="closeReason"
            >
                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                    <h2 class="text-lg font-semibold text-ink">
                        {{
                            reasonAction === 'void'
                                ? 'Void pesanan'
                                : 'Batalkan pesanan'
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ reasonOrder.order_number }} ·
                        {{
                            reasonAction === 'void'
                                ? 'Pembayaran akan di-void dan tercatat di audit.'
                                : 'Pesanan akan dibatalkan dan tidak bisa dilanjutkan.'
                        }}
                    </p>

                    <label class="mt-4 block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">
                            Alasan
                        </span>
                        <textarea
                            v-model="reason"
                            rows="3"
                            class="w-full rounded-xl border border-line bg-white px-3.5 py-3 text-base text-ink outline-none placeholder:text-muted/70 focus:border-brand focus:ring-2 focus:ring-brand/20"
                            placeholder="Tulis alasan…"
                        />
                    </label>

                    <div
                        v-if="reasonError"
                        class="mt-2 rounded-xl border border-danger/20 bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ reasonError }}
                    </div>

                    <div class="mt-5 flex gap-2">
                        <Button
                            variant="secondary"
                            class="flex-1"
                            :disabled="processing"
                            @click="closeReason"
                        >
                            Batal
                        </Button>
                        <Button
                            variant="danger"
                            class="flex-1"
                            :disabled="processing"
                            @click="submitReason"
                        >
                            {{
                                processing
                                    ? 'Memproses…'
                                    : reasonAction === 'void'
                                      ? 'Void pesanan'
                                      : 'Batalkan pesanan'
                            }}
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </StaffLayout>
</template>
