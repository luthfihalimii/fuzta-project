<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { formatRupiah } from '@/lib/money';

type PurchaseItemRow = {
    id: number;
    ingredient_name: string | null;
    unit_code: string | null;
    quantity: number;
    unit_price: number;
    line_total: number;
};

type PurchaseDetail = {
    id: number;
    received_at: string | null;
    reference: string | null;
    total: number;
    supplier_name: string | null;
    branch_name: string | null;
    recorded_by: string | null;
    created_at: string | null;
    items: PurchaseItemRow[];
};

const props = defineProps<{
    purchase: PurchaseDetail;
}>();

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`));
}
</script>

<template>
    <Head :title="`Pembelian #${props.purchase.id}`" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">
                        Pembelian #{{ props.purchase.id }}
                    </h1>
                    <p class="mt-1 text-sm text-muted">
                        Detail penerimaan barang.
                    </p>
                </div>
                <Link
                    href="/admin/purchases"
                    class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                >
                    Kembali
                </Link>
            </div>

            <section class="rounded-2xl border border-line bg-white p-5">
                <h2 class="font-semibold text-ink">Dokumen penerimaan</h2>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Supplier
                        </dt>
                        <dd class="mt-1 font-medium text-ink">
                            {{ props.purchase.supplier_name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Cabang
                        </dt>
                        <dd class="mt-1 font-medium text-ink">
                            {{ props.purchase.branch_name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Tanggal diterima
                        </dt>
                        <dd class="mt-1 font-medium text-ink">
                            {{ formatDate(props.purchase.received_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Nota
                        </dt>
                        <dd class="mt-1 font-medium text-ink">
                            {{ props.purchase.reference ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Pencatat
                        </dt>
                        <dd class="mt-1 font-medium text-ink">
                            {{ props.purchase.recorded_by ?? '—' }} ·
                            {{ formatDateTime(props.purchase.created_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs tracking-wide text-muted uppercase">
                            Total
                        </dt>
                        <dd class="mt-1 font-semibold text-ink tabular-nums">
                            {{ formatRupiah(props.purchase.total) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">
                        Baris bahan ({{ props.purchase.items.length }})
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3">Satuan entri</th>
                                <th class="px-5 py-3 text-right">Jumlah</th>
                                <th class="px-5 py-3 text-right">
                                    Harga/satuan
                                </th>
                                <th class="px-5 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="item in props.purchase.items"
                                :key="item.id"
                            >
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ item.ingredient_name ?? '—' }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ item.unit_code ?? '—' }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right text-ink tabular-nums"
                                >
                                    {{ item.quantity }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right text-muted tabular-nums"
                                >
                                    {{ formatRupiah(item.unit_price) }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right font-semibold text-ink tabular-nums"
                                >
                                    {{ formatRupiah(item.line_total) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
