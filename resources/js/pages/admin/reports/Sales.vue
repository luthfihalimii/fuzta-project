<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatRupiah } from '@/lib/money';

type BranchOption = {
    id: number;
    code: string;
    name: string;
};

type PaymentSplit = {
    method: string;
    label: string;
    order_count: number;
    amount: number;
};

type SalesSummary = {
    order_count: number;
    gross_sales: number;
    average_order_value: number;
    by_payment_method: PaymentSplit[];
};

type ProductRow = {
    product_id: number;
    product_name: string;
    quantity: number;
    line_total: number;
};

type ModifierRow = {
    modifier_name: string;
    quantity: number;
    revenue: number;
};

const props = defineProps<{
    filters: {
        from: string;
        to: string;
        branch: number | null;
    };
    branches: BranchOption[];
    sales: SalesSummary;
    products: ProductRow[];
    modifiers: ModifierRow[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const branch = ref(props.filters.branch ? String(props.filters.branch) : '');

function applyFilters(): void {
    router.get(
        '/admin/reports/sales',
        {
            from: from.value,
            to: to.value,
            ...(branch.value ? { branch: branch.value } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Laporan Penjualan" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Laporan penjualan</h1>
                    <p class="mt-1 text-sm text-muted">
                        Penjualan pesanan lunas dan jumlah produk terjual.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/reports/stock"
                        class="inline-flex min-h-11 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Laporan stok
                    </Link>
                    <Link
                        href="/admin/audit"
                        class="inline-flex min-h-11 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Audit
                    </Link>
                </div>
            </div>

            <form
                class="grid gap-4 rounded-2xl border border-line bg-white p-5 sm:grid-cols-2 lg:grid-cols-4"
                @submit.prevent="applyFilters"
            >
                <Input v-model="from" type="date" label="Dari tanggal" />
                <Input v-model="to" type="date" label="Sampai tanggal" />

                <label class="block">
                    <span class="mb-1.5 block text-sm font-medium text-ink">
                        Cabang
                    </span>
                    <select
                        v-model="branch"
                        class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20"
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

                <div class="flex items-end">
                    <Button type="submit" class="w-full">Terapkan</Button>
                </div>
            </form>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-line bg-white p-5">
                    <p class="text-sm text-muted">Total penjualan</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-ink">
                        {{ formatRupiah(props.sales.gross_sales) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-line bg-white p-5">
                    <p class="text-sm text-muted">Pesanan lunas</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-ink">
                        {{ props.sales.order_count }}
                    </p>
                </div>
                <div class="rounded-2xl border border-line bg-white p-5">
                    <p class="text-sm text-muted">Rata-rata per pesanan</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-ink">
                        {{ formatRupiah(props.sales.average_order_value) }}
                    </p>
                </div>
            </div>

            <div v-if="props.sales.order_count === 0">
                <EmptyState
                    title="Belum ada penjualan"
                    description="Tidak ada pesanan lunas pada rentang tanggal dan cabang ini."
                />
            </div>

            <template v-else>
                <section class="rounded-2xl border border-line bg-white">
                    <div class="border-b border-line px-5 py-4">
                        <h2 class="font-semibold text-ink">Metode pembayaran</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="bg-canvas text-xs tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-5 py-3">Metode</th>
                                    <th class="px-5 py-3 text-right">Pesanan</th>
                                    <th class="px-5 py-3 text-right">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="row in props.sales.by_payment_method"
                                    :key="row.method"
                                >
                                    <td class="px-5 py-4 font-medium text-ink">
                                        {{ row.label }}
                                    </td>
                                    <td class="px-5 py-4 text-right tabular-nums text-muted">
                                        {{ row.order_count }}
                                    </td>
                                    <td class="px-5 py-4 text-right font-semibold tabular-nums text-ink">
                                        {{ formatRupiah(row.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white">
                    <div class="border-b border-line px-5 py-4">
                        <h2 class="font-semibold text-ink">Produk terjual</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="bg-canvas text-xs tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-5 py-3">Produk</th>
                                    <th class="px-5 py-3 text-right">Jumlah</th>
                                    <th class="px-5 py-3 text-right">Nilai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="row in props.products"
                                    :key="row.product_id"
                                >
                                    <td class="px-5 py-4 font-medium text-ink">
                                        {{ row.product_name }}
                                    </td>
                                    <td class="px-5 py-4 text-right tabular-nums text-muted">
                                        {{ row.quantity }}
                                    </td>
                                    <td class="px-5 py-4 text-right font-semibold tabular-nums text-ink">
                                        {{ formatRupiah(row.line_total) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    v-if="props.modifiers.length > 0"
                    class="rounded-2xl border border-line bg-white"
                >
                    <div class="border-b border-line px-5 py-4">
                        <h2 class="font-semibold text-ink">Modifier terjual</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="bg-canvas text-xs tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-5 py-3">Modifier</th>
                                    <th class="px-5 py-3 text-right">Jumlah</th>
                                    <th class="px-5 py-3 text-right">Nilai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="row in props.modifiers"
                                    :key="row.modifier_name"
                                >
                                    <td class="px-5 py-4 font-medium text-ink">
                                        {{ row.modifier_name }}
                                    </td>
                                    <td class="px-5 py-4 text-right tabular-nums text-muted">
                                        {{ row.quantity }}
                                    </td>
                                    <td class="px-5 py-4 text-right font-semibold tabular-nums text-ink">
                                        {{ formatRupiah(row.revenue) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>
    </StaffLayout>
</template>
