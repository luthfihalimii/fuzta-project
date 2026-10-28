<script setup lang="ts">
import Money from '@/components/Money.vue';

type Row = {
    date: string;
    branch_code: string | null;
    branch_name: string | null;
    cashier_name: string | null;
    closed_shift_count: number;
    open_count: number;
    unreconciled_count: number;
    cash_sales_total: number;
    qris_sales_total: number;
    cash_in_total: number;
    cash_out_total: number;
    expected_cash: number;
    counted_cash: number;
    variance: number;
    cash_deposited: number;
};

defineProps<{ rows: Row[] }>();
</script>

<template>
    <div class="overflow-x-auto rounded-2xl border border-line bg-white">
        <table class="w-full min-w-[1050px] text-left text-sm">
            <thead class="bg-canvas text-xs tracking-wide text-muted uppercase">
                <tr>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Cabang</th>
                    <th class="px-4 py-3">Kasir</th>
                    <th class="px-4 py-3">Tutup</th>
                    <th class="px-4 py-3">Buka</th>
                    <th class="px-4 py-3">Belum rekon</th>
                    <th class="px-4 py-3 text-right">Tunai</th>
                    <th class="px-4 py-3 text-right">QRIS</th>
                    <th class="px-4 py-3 text-right">Masuk</th>
                    <th class="px-4 py-3 text-right">Keluar</th>
                    <th class="px-4 py-3 text-right">Seharusnya</th>
                    <th class="px-4 py-3 text-right">Dihitung</th>
                    <th class="px-4 py-3 text-right">Selisih</th>
                    <th class="px-4 py-3 text-right">Setoran</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr
                    v-for="row in rows"
                    :key="`${row.date}-${row.branch_code}-${row.cashier_name}`"
                >
                    <td class="px-4 py-3 text-muted">{{ row.date }}</td>
                    <td class="px-4 py-3 text-ink">
                        {{ row.branch_code }} · {{ row.branch_name }}
                    </td>
                    <td class="px-4 py-3 text-ink">
                        {{ row.cashier_name ?? '—' }}
                    </td>
                    <td class="px-4 py-3">{{ row.closed_shift_count }}</td>
                    <td class="px-4 py-3">{{ row.open_count }}</td>
                    <td class="px-4 py-3">{{ row.unreconciled_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.cash_sales_total" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.qris_sales_total" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.cash_in_total" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.cash_out_total" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.expected_cash" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.counted_cash" />
                    </td>
                    <td
                        class="px-4 py-3 text-right"
                        :class="
                            row.variance === 0 ? 'text-success' : 'text-danger'
                        "
                    >
                        <Money :value="row.variance" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <Money :value="row.cash_deposited" />
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td colspan="14" class="px-4 py-8 text-center text-muted">
                        Belum ada data.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
