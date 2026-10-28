<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/Badge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type LowStockRow = {
    ingredient_id: number;
    ingredient_name: string;
    quantity: number;
    low_stock_threshold: number;
    unit: string;
    branch_id: number;
    branch_code: string;
    branch_name: string;
};

defineProps<{
    low_stock: LowStockRow[];
    shift_attention: {
        overdue: number;
        unreconciled: number;
        unpaid: number;
    };
}>();
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-ink">Dashboard Pemilik</h1>
                <p class="mt-1 text-sm text-muted">
                    Kelola staf, cabang, dan operasional Pizza Fruzta.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    href="/admin/catalog/products"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Katalog</h2>
                    <p class="mt-1 text-sm text-muted">
                        Kelola kategori, produk, varian, dan modifier.
                    </p>
                </Link>

                <Link
                    href="/admin/stock/ingredients"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Stok</h2>
                    <p class="mt-1 text-sm text-muted">
                        Bahan, ledger, opname, dan stok menipis.
                    </p>
                </Link>

                <Link
                    href="/admin/reports/sales"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Laporan penjualan</h2>
                    <p class="mt-1 text-sm text-muted">
                        Omzet, metode bayar, dan jumlah produk terjual.
                    </p>
                </Link>

                <Link
                    href="/admin/reports/stock"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Laporan stok</h2>
                    <p class="mt-1 text-sm text-muted">
                        Saldo bahan, stok menipis, dan pergerakan.
                    </p>
                </Link>

                <Link
                    href="/admin/shifts?status=unreconciled"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">
                        Shift perlu perhatian
                    </h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ shift_attention.unreconciled }} perlu rekonsiliasi ·
                        {{ shift_attention.overdue }} lewat jadwal ·
                        {{ shift_attention.unpaid }} pesanan tertunda.
                    </p>
                </Link>

                <Link
                    href="/admin/audit"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Audit</h2>
                    <p class="mt-1 text-sm text-muted">
                        Riwayat tindakan penting dan perubahannya.
                    </p>
                </Link>

                <Link
                    href="/admin/staff"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Staf</h2>
                    <p class="mt-1 text-sm text-muted">
                        Tambah, ubah peran, dan nonaktifkan akun staf.
                    </p>
                </Link>

                <Link
                    href="/admin/branches"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Cabang</h2>
                    <p class="mt-1 text-sm text-muted">
                        Lihat dan kelola cabang yang beroperasi.
                    </p>
                </Link>
            </div>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold text-ink">Stok menipis</h2>
                        <p class="mt-1 text-sm text-muted">
                            Bahan pada atau di bawah ambang minimum.
                        </p>
                    </div>
                    <Badge :tone="low_stock.length > 0 ? 'warning' : 'success'">
                        {{ low_stock.length }} bahan
                    </Badge>
                </div>

                <div
                    v-if="low_stock.length === 0"
                    class="px-5 py-8 text-center text-sm text-muted"
                >
                    Semua stok di atas ambang minimum.
                </div>

                <div v-else class="divide-y divide-line">
                    <div
                        v-for="row in low_stock"
                        :key="`${row.branch_id}-${row.ingredient_id}`"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                    >
                        <div>
                            <p class="font-semibold text-ink">
                                {{ row.ingredient_name }}
                            </p>
                            <p class="mt-0.5 text-sm text-muted">
                                {{ row.branch_code }} — {{ row.branch_name }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-warning tabular-nums">
                                {{ row.quantity }} {{ row.unit }}
                            </p>
                            <p class="text-xs text-muted">
                                Minimum {{ row.low_stock_threshold }}
                                {{ row.unit }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
