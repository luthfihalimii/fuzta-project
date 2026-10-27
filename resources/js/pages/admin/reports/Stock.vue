<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';

type BranchOption = {
    id: number;
    code: string;
    name: string;
};

type BalanceRow = {
    branch_id: number;
    branch_code: string;
    branch_name: string;
    ingredient_id: number;
    ingredient_name: string;
    quantity: number;
    low_stock_threshold: number;
    unit: string;
    is_low_stock: boolean;
};

type MovementRow = {
    id: number;
    branch_id: number;
    branch_code: string | null;
    branch_name: string | null;
    ingredient_name: string | null;
    unit: string | null;
    quantity: number;
    type: string;
    type_label: string;
    reason: string;
    actor_name: string | null;
    created_at: string | null;
};

const props = defineProps<{
    filters: {
        branch: number | null;
    };
    branches: BranchOption[];
    balances: BalanceRow[];
    low_stock: BalanceRow[];
    movements: MovementRow[];
}>();

const branch = ref(props.filters.branch ? String(props.filters.branch) : '');
const movementFrom = ref('');
const movementTo = ref('');
const movementType = ref('');

const typeOptions = [
    { value: 'opening', label: 'Stok awal' },
    { value: 'receipt', label: 'Penerimaan' },
    { value: 'waste', label: 'Rusak' },
    { value: 'adjustment', label: 'Koreksi' },
    { value: 'count_correction', label: 'Koreksi opname' },
];

const jakartaDateFormatter = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Asia/Jakarta',
});

const filteredMovements = computed(() =>
    props.movements.filter((movement) => {
        const date = dateInJakarta(movement.created_at);

        if (movementFrom.value && date < movementFrom.value) {
            return false;
        }

        if (movementTo.value && date > movementTo.value) {
            return false;
        }

        if (movementType.value && movement.type !== movementType.value) {
            return false;
        }

        return true;
    }),
);

function dateInJakarta(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : jakartaDateFormatter.format(date);
}

function applyBranch(): void {
    router.get(
        '/admin/reports/stock',
        branch.value ? { branch: branch.value } : {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function typeTone(
    type: string,
): 'info' | 'success' | 'danger' | 'warning' | 'neutral' {
    return {
        opening: 'info',
        receipt: 'success',
        waste: 'danger',
        adjustment: 'warning',
        count_correction: 'neutral',
    }[type] as 'info' | 'success' | 'danger' | 'warning' | 'neutral';
}

function signed(value: number): string {
    return value > 0 ? `+${value}` : String(value);
}
</script>

<template>
    <Head title="Laporan Stok" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Laporan stok</h1>
                    <p class="mt-1 text-sm text-muted">
                        Saldo bahan, stok menipis, dan riwayat pergerakan.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/reports/sales"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Laporan penjualan
                    </Link>
                    <Link
                        href="/admin/audit"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Audit
                    </Link>
                </div>
            </div>

            <form
                class="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-white p-5"
                @submit.prevent="applyBranch"
            >
                <label class="block min-w-56">
                    <span class="mb-1.5 block text-sm font-medium text-ink">
                        Cabang
                    </span>
                    <select
                        v-model="branch"
                        class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    >
                        <option value="">Semua cabang</option>
                        <option
                            v-for="option in props.branches"
                            :key="option.id"
                            :value="String(option.id)"
                        >
                            {{ option.code }} — {{ option.name }}
                        </option>
                    </select>
                </label>

                <Button type="submit">Terapkan</Button>
            </form>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-line bg-white p-5">
                    <p class="text-sm text-muted">Baris saldo bahan</p>
                    <p class="mt-2 text-2xl font-bold text-ink tabular-nums">
                        {{ props.balances.length }}
                    </p>
                </div>
                <div class="rounded-2xl border border-line bg-white p-5">
                    <p class="text-sm text-muted">Bahan menipis</p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            props.low_stock.length > 0
                                ? 'text-warning'
                                : 'text-success'
                        "
                    >
                        {{ props.low_stock.length }}
                    </p>
                </div>
            </div>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Stok menipis</h2>
                    <Badge
                        :tone="
                            props.low_stock.length > 0 ? 'warning' : 'success'
                        "
                    >
                        {{ props.low_stock.length }} bahan
                    </Badge>
                </div>

                <div
                    v-if="props.low_stock.length === 0"
                    class="px-5 py-8 text-center text-sm text-muted"
                >
                    Semua bahan berada di atas ambang minimum.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3 text-right">Stok</th>
                                <th class="px-5 py-3 text-right">Minimum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="row in props.low_stock"
                                :key="`${row.branch_id}-${row.ingredient_id}`"
                            >
                                <td class="px-5 py-4 text-muted">
                                    {{ row.branch_code }} —
                                    {{ row.branch_name }}
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ row.ingredient_name }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right font-semibold text-warning tabular-nums"
                                >
                                    {{ row.quantity }} {{ row.unit }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right text-muted tabular-nums"
                                >
                                    {{ row.low_stock_threshold }} {{ row.unit }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Saldo bahan</h2>
                </div>

                <div v-if="props.balances.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada bahan"
                        description="Tambahkan bahan dan stok awal terlebih dahulu."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3 text-right">Stok</th>
                                <th class="px-5 py-3 text-right">Minimum</th>
                                <th class="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="row in props.balances"
                                :key="`${row.branch_id}-${row.ingredient_id}`"
                            >
                                <td class="px-5 py-4 text-muted">
                                    {{ row.branch_code }} —
                                    {{ row.branch_name }}
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ row.ingredient_name }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right font-semibold text-ink tabular-nums"
                                >
                                    {{ row.quantity }} {{ row.unit }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right text-muted tabular-nums"
                                >
                                    {{ row.low_stock_threshold }} {{ row.unit }}
                                </td>
                                <td class="px-5 py-4">
                                    <Badge
                                        :tone="
                                            row.is_low_stock
                                                ? 'warning'
                                                : 'success'
                                        "
                                    >
                                        {{
                                            row.is_low_stock
                                                ? 'Menipis'
                                                : 'Aman'
                                        }}
                                    </Badge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold text-ink">Pergerakan stok</h2>
                        <p class="mt-1 text-sm text-muted">
                            Menampilkan maksimal 200 pergerakan terbaru.
                        </p>
                    </div>
                    <Badge tone="info">
                        {{ filteredMovements.length }} baris
                    </Badge>
                </div>

                <div
                    class="grid gap-3 border-b border-line bg-canvas/60 px-5 py-4 sm:grid-cols-4"
                >
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">
                            Dari tanggal
                        </span>
                        <input
                            v-model="movementFrom"
                            type="date"
                            class="min-h-12 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">
                            Sampai tanggal
                        </span>
                        <input
                            v-model="movementTo"
                            type="date"
                            class="min-h-12 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-1.5 block text-sm font-medium text-ink">
                            Jenis
                        </span>
                        <select
                            v-model="movementType"
                            class="min-h-12 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                        >
                            <option value="">Semua jenis</option>
                            <option
                                v-for="option in typeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <div v-if="filteredMovements.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada pergerakan"
                        description="Tidak ada pergerakan stok pada filter ini."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Waktu</th>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3">Jenis</th>
                                <th class="px-5 py-3 text-right">Jumlah</th>
                                <th class="px-5 py-3">Alasan</th>
                                <th class="px-5 py-3">Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="movement in filteredMovements"
                                :key="movement.id"
                            >
                                <td
                                    class="px-5 py-4 whitespace-nowrap text-muted"
                                >
                                    {{ formatDateTime(movement.created_at) }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ movement.branch_code ?? '—' }}
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ movement.ingredient_name ?? '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <Badge :tone="typeTone(movement.type)">
                                        {{ movement.type_label }}
                                    </Badge>
                                </td>
                                <td
                                    class="px-5 py-4 text-right font-semibold tabular-nums"
                                    :class="
                                        movement.quantity < 0
                                            ? 'text-danger'
                                            : 'text-success'
                                    "
                                >
                                    {{ signed(movement.quantity) }}
                                    <span
                                        class="text-xs font-normal text-muted"
                                    >
                                        {{ movement.unit }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ movement.reason }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ movement.actor_name ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
