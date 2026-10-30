<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatRupiah } from '@/lib/money';
import type { BranchOption, BranchSummary } from '@/lib/order';

type PurchaseRow = {
    id: number;
    received_at: string | null;
    reference: string | null;
    supplier_name: string | null;
    branch_name: string | null;
    total: number;
    recorded_by: string | null;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    purchases: PurchaseRow[];
}>();

const page = usePage();
const branchSelect = ref<number | null>(props.branch?.id ?? null);

function changeBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/admin/purchases',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

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
    <Head title="Pembelian" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Pembelian</h1>
                    <p class="mt-1 text-sm text-muted">
                        Riwayat penerimaan barang dari pemasok.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/purchases/create"
                        class="inline-flex min-h-12 items-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand/90"
                    >
                        Terima barang
                    </Link>
                    <Link
                        href="/admin/suppliers"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Supplier
                    </Link>
                </div>
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

            <div
                v-if="props.branches.length > 0"
                class="flex flex-wrap items-center gap-3"
            >
                <label
                    for="purchase-branch"
                    class="text-sm font-medium text-ink"
                >
                    Cabang
                </label>
                <select
                    id="purchase-branch"
                    v-model="branchSelect"
                    class="min-h-12 rounded-xl border border-line bg-white px-3 text-sm font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    @change="changeBranch"
                >
                    <option
                        v-for="option in props.branches"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.code }} — {{ option.name }}
                    </option>
                </select>
                <span v-if="props.branch" class="text-sm text-muted">
                    {{ props.branch.name }}
                </span>
            </div>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Riwayat pembelian</h2>
                    <Badge tone="info"
                        >{{ props.purchases.length }} penerimaan</Badge
                    >
                </div>

                <div v-if="props.purchases.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada pembelian"
                        description="Catat penerimaan barang pertama lewat tombol Terima barang."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Tanggal</th>
                                <th class="px-5 py-3">Nota</th>
                                <th class="px-5 py-3">Supplier</th>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3 text-right">Total</th>
                                <th class="px-5 py-3">Pencatat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="purchase in props.purchases"
                                :key="purchase.id"
                            >
                                <td
                                    class="px-5 py-4 whitespace-nowrap text-muted"
                                >
                                    <Link
                                        :href="`/admin/purchases/${purchase.id}`"
                                        class="font-medium text-ink hover:text-brand"
                                    >
                                        {{ formatDate(purchase.received_at) }}
                                    </Link>
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    <Link
                                        :href="`/admin/purchases/${purchase.id}`"
                                        class="hover:text-brand"
                                    >
                                        {{ purchase.reference ?? '—' }}
                                    </Link>
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ purchase.supplier_name ?? '—' }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ purchase.branch_name ?? '—' }}
                                </td>
                                <td
                                    class="px-5 py-4 text-right font-semibold text-ink tabular-nums"
                                >
                                    {{ formatRupiah(purchase.total) }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ purchase.recorded_by ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
