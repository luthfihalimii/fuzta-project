<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { realtimeEcho } from '@/bootstrap/echo';
import { formatElapsed } from '@/lib/datetime';
import { getJson, HttpError, patchJson } from '@/lib/http';
import type { BranchOption, BranchSummary, PosOrder } from '@/lib/order';
import { isLate, shouldRing, type RealtimeNotification } from '@/lib/realtime';
import { playBeep, unlockAudio } from '@/lib/sound';
import { channelLabel } from '@/lib/status';

type KitchenStatus =
    | 'queued'
    | 'accepted'
    | 'preparing'
    | 'ready'
    | 'completed';

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    kitchenLateMinutes: number;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const tabs: { value: KitchenStatus; label: string }[] = [
    { value: 'queued', label: 'Baru' },
    { value: 'accepted', label: 'Diterima' },
    { value: 'preparing', label: 'Disiapkan' },
    { value: 'ready', label: 'Siap' },
    { value: 'completed', label: 'Selesai' },
];

const nextActions: Record<string, { status: KitchenStatus; label: string }> = {
    queued: { status: 'accepted', label: 'Terima pesanan' },
    accepted: { status: 'preparing', label: 'Mulai masak' },
    preparing: { status: 'ready', label: 'Siap disajikan' },
    ready: { status: 'completed', label: 'Selesaikan' },
};

const statusTone: Record<KitchenStatus, string> = {
    queued: 'bg-brand-yellow text-ink',
    accepted: 'bg-kitchen-accepted text-ink',
    preparing: 'bg-brand-orange text-ink',
    ready: 'bg-kitchen-ready text-ink',
    completed: 'bg-white/10 text-kitchen-muted',
};

const activeStatus = ref<KitchenStatus>('queued');
const orders = ref<PosOrder[]>([]);
const loading = ref(true);
const error = ref('');
const actionError = ref('');
const transitioningId = ref<number | null>(null);
const newOrderIds = ref(new Set<number>());
const branchSelect = ref<number | null>(
    props.branch?.id ?? props.branches[0]?.id ?? null,
);

const soundOn = ref(sessionStorage.getItem('pf_kitchen_muted') !== '1');
const seen = new Set<string>(
    sessionStorage.getItem('pf_kitchen_seen')?.split(',').filter(Boolean) ?? [],
);
const now = ref(Date.now());

let pollTimer: number | undefined;
let requestId = 0;
let newOrderTimer: number | undefined;
let nowTimer: number | undefined;
let realtimeChannel: string | null = null;
let unbindConnection: (() => void) | null = null;

const activeTab = computed(
    () => tabs.find((tab) => tab.value === activeStatus.value) ?? tabs[0],
);

const emptyMessage = computed(() => {
    return {
        queued: 'Belum ada pesanan baru.',
        accepted: 'Belum ada pesanan yang diterima.',
        preparing: 'Belum ada pesanan yang sedang disiapkan.',
        ready: 'Belum ada pesanan siap disajikan.',
        completed: 'Belum ada pesanan selesai.',
    }[activeStatus.value];
});

async function loadOrders(initial = false): Promise<void> {
    const currentRequest = ++requestId;
    const branch = props.branch;

    if (!branch) {
        orders.value = [];
        loading.value = false;
        return;
    }

    if (initial) {
        loading.value = true;
    }

    try {
        const data = await getJson<{ orders: PosOrder[] }>(
            `/kitchen/orders?branch=${branch.id}&status=${activeStatus.value}`,
        );

        if (currentRequest !== requestId) {
            return;
        }

        if (!initial) {
            const previousIds = new Set(orders.value.map((order) => order.id));

            for (const order of data.orders) {
                if (order.status === 'queued' && !previousIds.has(order.id)) {
                    newOrderIds.value.add(order.id);
                }
            }

            if (newOrderIds.value.size > 0) {
                window.clearTimeout(newOrderTimer);
                newOrderTimer = window.setTimeout(() => {
                    newOrderIds.value.clear();
                }, 4000);
            }
        }

        orders.value = data.orders;
        error.value = '';
    } catch (caught) {
        if (currentRequest !== requestId) {
            return;
        }

        error.value =
            caught instanceof HttpError
                ? caught.firstError()
                : 'Gagal memuat pesanan. Mencoba lagi...';
    } finally {
        if (currentRequest === requestId) {
            loading.value = false;
        }
    }
}

function selectTab(status: KitchenStatus): void {
    if (activeStatus.value === status) {
        return;
    }

    activeStatus.value = status;
    orders.value = [];
    error.value = '';
    actionError.value = '';
    newOrderIds.value.clear();
    loading.value = true;
    void loadOrders(true);
}

async function advance(order: PosOrder): Promise<void> {
    const action = nextActions[order.status];

    if (!action) {
        return;
    }

    transitioningId.value = order.id;
    actionError.value = '';

    try {
        await patchJson(`/kitchen/orders/${order.id}/status`, {
            status: action.status,
        });
        await loadOrders();
    } catch (caught) {
        actionError.value =
            caught instanceof HttpError
                ? caught.firstError()
                : 'Gagal memperbarui pesanan. Coba lagi.';
    } finally {
        transitioningId.value = null;
    }
}

function changeBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/kitchen',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

function logout(): void {
    router.post('/logout');
}

function toggleSound(): void {
    soundOn.value = !soundOn.value;
    sessionStorage.setItem('pf_kitchen_muted', soundOn.value ? '0' : '1');

    if (soundOn.value) {
        unlockAudio();
    }
}

function onRealtime(payload: RealtimeNotification): void {
    if (payload.branch_id !== props.branch?.id) {
        return;
    }

    if (shouldRing(payload, seen)) {
        seen.add(payload.order_number);
        sessionStorage.setItem('pf_kitchen_seen', [...seen].join(','));

        if (soundOn.value && document.visibilityState === 'visible') {
            playBeep();
        }
    }

    void loadOrders();
}

function connectRealtime(): void {
    const echo = realtimeEcho();
    const branch = props.branch;

    if (!echo || !branch) {
        // fallback: polling 5 detik di onMounted tetap jalan
        return;
    }

    realtimeChannel = `kitchen.${branch.id}`;

    echo.private(realtimeChannel)
        // prefix titik wajib: broadcastAs() server memakai nama polos,
        // tanpa titik echo menambahkan namespace App\Events
        .listen('.order-created', onRealtime)
        .listen('.order-status-changed', onRealtime)
        .listen('.order-payment-changed', onRealtime);

    unbindConnection = echo.connector.onConnectionChange((status) => {
        if (status === 'connected') {
            void loadOrders();
        }
    });
}

onMounted(() => {
    // browser menahan AudioContext sampai gestur pertama; tanpa ini beep pertama dibuang
    window.addEventListener('pointerdown', unlockAudio, { once: true });
    void loadOrders(true);
    pollTimer = window.setInterval(() => void loadOrders(), 5000);
    nowTimer = window.setInterval(() => {
        now.value = Date.now();
    }, 30_000);
    connectRealtime();
});

onUnmounted(() => {
    window.clearInterval(pollTimer);
    window.clearTimeout(newOrderTimer);
    window.clearInterval(nowTimer);
    unbindConnection?.();

    if (realtimeChannel !== null) {
        realtimeEcho()?.leave(realtimeChannel);
    }
});
</script>

<template>
    <Head title="Dapur" />

    <div class="min-h-screen bg-kitchen-canvas text-kitchen-text">
        <header
            class="sticky top-0 z-20 border-b border-white/10 bg-kitchen-canvas/95 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Link
                        href="/"
                        class="inline-flex min-h-12 shrink-0 items-center text-lg font-black tracking-tight text-brand-yellow 2xl:text-2xl"
                    >
                        Pizza Fruzta
                    </Link>
                    <h1 class="text-sm font-semibold text-white/60">Dapur</h1>
                    <span
                        v-if="branch"
                        class="truncate text-sm font-semibold text-white/80"
                    >
                        {{ branch.name }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="canChooseBranch && branches.length > 0">
                        <label for="kitchen-branch" class="sr-only"
                            >Cabang</label
                        >
                        <select
                            id="kitchen-branch"
                            v-model="branchSelect"
                            class="min-h-12 rounded-xl border border-white/15 bg-white/5 px-3 text-sm font-semibold text-white outline-none focus:border-brand-yellow focus:ring-2 focus:ring-brand-yellow/30"
                            @change="changeBranch"
                        >
                            <option
                                v-for="option in branches"
                                :key="option.id"
                                :value="option.id"
                                class="bg-kitchen-surface"
                            >
                                {{ option.name }}
                            </option>
                        </select>
                    </template>

                    <Link
                        href="/kitchen/stock"
                        class="inline-flex min-h-12 items-center rounded-xl border border-white/15 px-3 text-sm font-semibold text-white/80 transition hover:bg-white/10"
                    >
                        Stok
                    </Link>

                    <button
                        type="button"
                        :aria-pressed="soundOn"
                        :aria-label="
                            soundOn ? 'Matikan suara' : 'Nyalakan suara'
                        "
                        class="inline-flex min-h-12 items-center gap-2 rounded-xl border border-white/15 px-3 text-sm font-semibold transition hover:bg-white/10"
                        :class="soundOn ? 'text-brand-yellow' : 'text-white/60'"
                        @click="toggleSound"
                    >
                        <svg
                            aria-hidden="true"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-5"
                        >
                            <path d="M18 8a6 6 0 0 1 0 8" />
                            <path d="M4 9v6h4l5 4V5L8 9H4z" />
                        </svg>
                        Suara
                    </button>

                    <Link
                        v-if="user?.role === 'owner'"
                        href="/admin"
                        class="hidden min-h-12 items-center rounded-xl border border-white/15 px-3 text-sm font-semibold text-white/80 transition hover:bg-white/10 sm:flex"
                    >
                        Admin
                    </Link>

                    <span class="hidden text-sm text-white/65 md:block">
                        {{ user?.name }}
                    </span>

                    <button
                        type="button"
                        class="min-h-12 rounded-xl px-3 text-sm font-semibold text-white/60 transition hover:bg-white/10 hover:text-white"
                        @click="logout"
                    >
                        Keluar
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1600px] px-4 py-5 sm:px-6">
            <div
                class="mb-4 flex items-center gap-2 overflow-x-auto pb-1"
                role="group"
                aria-label="Filter status pesanan"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    :aria-pressed="activeStatus === tab.value"
                    class="min-h-12 shrink-0 rounded-xl px-4 text-base font-bold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-yellow 2xl:min-h-14 2xl:px-6 2xl:text-lg"
                    :class="
                        activeStatus === tab.value
                            ? 'bg-brand-yellow text-ink'
                            : 'bg-white/5 text-white/65 hover:bg-white/10 hover:text-white'
                    "
                    @click="selectTab(tab.value)"
                >
                    {{ tab.label }}
                </button>

                <span
                    class="ml-auto hidden shrink-0 text-xs text-white/55 sm:block"
                >
                    Diperbarui otomatis tiap 5 detik
                </span>
            </div>

            <div
                v-if="error"
                class="mb-4 rounded-xl border border-brand-orange/40 bg-brand-orange/10 px-4 py-3 text-sm font-semibold text-brand-orange"
                role="alert"
                aria-live="polite"
            >
                {{ error }}
            </div>

            <div
                v-if="actionError"
                class="mb-4 rounded-xl border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-semibold text-kitchen-danger-text"
                role="alert"
                aria-live="polite"
            >
                {{ actionError }}
            </div>

            <div
                v-if="loading"
                class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"
                role="status"
                aria-live="polite"
                aria-label="Memuat pesanan"
            >
                <div
                    v-for="index in 4"
                    :key="index"
                    class="h-64 rounded-2xl border border-white/10 bg-white/5 motion-safe:animate-pulse"
                />
            </div>

            <div
                v-else-if="!branch"
                class="rounded-2xl border border-white/10 bg-white/5 px-6 py-16 text-center"
            >
                <p class="text-xl font-bold">Cabang belum tersedia.</p>
                <p class="mt-2 text-sm text-white/60">
                    Pilih cabang aktif terlebih dahulu.
                </p>
            </div>

            <div
                v-else-if="orders.length === 0"
                class="rounded-2xl border border-dashed border-white/15 bg-white/[0.03] px-6 py-16 text-center"
            >
                <p class="text-xl font-bold 2xl:text-3xl">{{ emptyMessage }}</p>
                <p class="mt-2 text-sm text-white/60 2xl:text-lg">
                    Halaman ini memperbarui daftar secara otomatis.
                </p>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                <article
                    v-for="order in orders"
                    :key="order.id"
                    class="flex flex-col rounded-2xl border bg-kitchen-surface p-4 shadow-lg shadow-black/20"
                    :class="
                        newOrderIds.has(order.id)
                            ? 'border-brand-yellow ring-2 ring-brand-yellow/60 motion-safe:animate-pulse'
                            : 'border-white/10'
                    "
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="text-xs font-bold tracking-[0.16em] text-white/55 uppercase"
                            >
                                {{ channelLabel(order.channel) }}
                                <span v-if="order.table_number">
                                    · Meja {{ order.table_number }}
                                </span>
                            </p>
                            <h2
                                class="mt-1 text-2xl font-black tracking-tight 2xl:text-3xl"
                            >
                                {{ order.order_number }}
                            </h2>
                            <p
                                v-if="order.customer_name"
                                class="mt-0.5 truncate text-sm text-white/60"
                            >
                                {{ order.customer_name }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-base font-black tracking-wide uppercase 2xl:px-3 2xl:py-1.5 2xl:text-lg"
                                :class="statusTone[activeStatus]"
                            >
                                {{ activeTab?.label }}
                            </span>
                            <p
                                class="mt-2 text-base font-semibold text-white/65 2xl:text-lg"
                            >
                                {{
                                    formatElapsed(
                                        order.accepted_at ?? order.created_at,
                                    )
                                }}
                            </p>
                            <p
                                v-if="
                                    activeStatus !== 'completed' &&
                                    isLate(
                                        order.accepted_at ?? order.created_at,
                                        now,
                                        kitchenLateMinutes,
                                    )
                                "
                                role="status"
                                class="mt-2 inline-flex rounded-full bg-danger px-2.5 py-1 text-xs font-black tracking-wide text-white uppercase"
                            >
                                Terlambat
                            </p>
                        </div>
                    </div>

                    <div class="my-4 h-px bg-white/10" />

                    <ul class="space-y-3">
                        <li
                            v-for="item in order.items"
                            :key="item.id"
                            class="text-lg leading-snug 2xl:text-2xl"
                        >
                            <div class="flex gap-2">
                                <span class="font-black text-brand-yellow">
                                    {{ item.quantity }}×
                                </span>
                                <div class="min-w-0">
                                    <p class="font-bold">
                                        {{ item.product_name }}
                                    </p>
                                    <p
                                        v-if="item.variant_name"
                                        class="text-sm text-white/60 2xl:text-lg"
                                    >
                                        {{ item.variant_name }}
                                    </p>
                                    <p
                                        v-for="(
                                            modifier, index
                                        ) in item.modifiers"
                                        :key="`${item.id}-${index}`"
                                        class="text-sm text-white/60 2xl:text-lg"
                                    >
                                        + {{ modifier.modifier_name }}
                                    </p>
                                    <p
                                        v-if="item.note"
                                        class="mt-1 text-base font-medium text-brand-yellow/80 2xl:text-xl"
                                    >
                                        Catatan item: {{ item.note }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-if="order.note"
                        class="mt-4 rounded-xl border border-brand-yellow/25 bg-brand-yellow/10 px-3 py-2 text-base font-semibold text-brand-yellow 2xl:text-xl"
                    >
                        Catatan: {{ order.note }}
                    </p>

                    <div class="mt-auto pt-4">
                        <button
                            v-if="nextActions[order.status]"
                            type="button"
                            class="min-h-14 w-full rounded-xl bg-brand-yellow px-4 text-lg font-black text-ink transition hover:bg-brand-yellow/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-yellow disabled:cursor-not-allowed disabled:opacity-50 2xl:min-h-16 2xl:text-xl"
                            :disabled="transitioningId === order.id"
                            @click="advance(order)"
                        >
                            {{
                                transitioningId === order.id
                                    ? 'Menyimpan...'
                                    : nextActions[order.status]?.label
                            }}
                        </button>
                        <p
                            v-else
                            class="rounded-xl bg-white/5 px-4 py-3 text-center text-sm font-semibold text-white/60"
                        >
                            Pesanan selesai.
                        </p>
                    </div>
                </article>
            </div>
        </main>
    </div>
</template>
