<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import ShiftDailySummaryTable from '@/components/shifts/ShiftDailySummaryTable.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import type { BranchOption } from '@/lib/order';

type ShiftRow = {
    id: number;
    branch_code: string | null;
    branch_name: string | null;
    cashier_name: string | null;
    window_key: string;
    opened_at: string | null;
    closed_at: string | null;
    opening_cash: number;
    cash_sales_total: number | null;
    qris_sales_total: number | null;
    expected_cash: number | null;
    counted_cash: number | null;
    variance: number | null;
    cash_deposited: number | null;
    status: 'open' | 'auto_closed_unreconciled' | 'closed';
};

type PaginatedShifts = {
    data: ShiftRow[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Filters = {
    from: string;
    to: string;
    branch: number | null;
    user: number | null;
    status: string | null;
};

const props = defineProps<{
    view: 'shifts' | 'daily';
    filters: Filters;
    branches: BranchOption[];
    users: { id: number; name: string }[];
    shifts: PaginatedShifts | null;
    daily: { rows: Array<Record<string, unknown>> } | null;
}>();

const filter = ref<Filters>({ ...props.filters });

function apply(): void {
    router.get(
        '/admin/shifts',
        {
            view: props.view,
            from: filter.value.from || undefined,
            to: filter.value.to || undefined,
            branch: filter.value.branch || undefined,
            user: filter.value.user || undefined,
            status: filter.value.status || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function tab(view: 'shifts' | 'daily'): void {
    router.get('/admin/shifts', { view }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Shift" />
    <StaffLayout>
        <div class="space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Shift</h1>
                    <p class="mt-1 text-sm text-muted">
                        Rekap kas per shift dan ringkasan harian.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="min-h-12 rounded-xl px-4 text-sm font-semibold"
                        :class="
                            view === 'shifts'
                                ? 'bg-brand text-white'
                                : 'border border-line bg-white text-ink'
                        "
                        @click="tab('shifts')"
                    >
                        Per shift
                    </button>
                    <button
                        type="button"
                        class="min-h-12 rounded-xl px-4 text-sm font-semibold"
                        :class="
                            view === 'daily'
                                ? 'bg-brand text-white'
                                : 'border border-line bg-white text-ink'
                        "
                        @click="tab('daily')"
                    >
                        Ringkasan harian
                    </button>
                </div>
            </div>

            <form
                class="grid gap-3 rounded-2xl border border-line bg-white p-4 sm:grid-cols-3 lg:grid-cols-6"
                @submit.prevent="apply"
            >
                <input
                    v-model="filter.from"
                    type="date"
                    class="min-h-12 rounded-xl border border-line px-3 text-sm"
                    aria-label="Dari tanggal"
                />
                <input
                    v-model="filter.to"
                    type="date"
                    class="min-h-12 rounded-xl border border-line px-3 text-sm"
                    aria-label="Sampai tanggal"
                />
                <select
                    v-model="filter.branch"
                    class="min-h-12 rounded-xl border border-line px-3 text-sm"
                    aria-label="Cabang"
                >
                    <option :value="null">Semua cabang</option>
                    <option
                        v-for="branch in branches"
                        :key="branch.id"
                        :value="branch.id"
                    >
                        {{ branch.code }} — {{ branch.name }}
                    </option>
                </select>
                <select
                    v-model="filter.user"
                    class="min-h-12 rounded-xl border border-line px-3 text-sm"
                    aria-label="Kasir"
                >
                    <option :value="null">Semua kasir</option>
                    <option
                        v-for="user in users"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }}
                    </option>
                </select>
                <select
                    v-if="view === 'shifts'"
                    v-model="filter.status"
                    class="min-h-12 rounded-xl border border-line px-3 text-sm"
                    aria-label="Status"
                >
                    <option :value="null">Semua status</option>
                    <option value="open">Terbuka</option>
                    <option value="unreconciled">Perlu rekonsiliasi</option>
                    <option value="closed">Ditutup</option>
                </select>
                <button
                    type="submit"
                    class="min-h-12 rounded-xl bg-brand px-4 text-sm font-semibold text-white"
                >
                    Terapkan
                </button>
            </form>

            <template v-if="view === 'shifts' && shifts">
                <EmptyState
                    v-if="shifts.data.length === 0"
                    title="Belum ada shift"
                    description="Shift yang dibuka kasir akan muncul di sini."
                />
                <div
                    v-else
                    class="overflow-x-auto rounded-2xl border border-line bg-white"
                >
                    <table class="w-full min-w-[1000px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Cabang</th>
                                <th class="px-4 py-3">Kasir</th>
                                <th class="px-4 py-3">Jendela</th>
                                <th class="px-4 py-3">Buka</th>
                                <th class="px-4 py-3">Tutup</th>
                                <th class="px-4 py-3 text-right">Kas awal</th>
                                <th class="px-4 py-3 text-right">Tunai</th>
                                <th class="px-4 py-3 text-right">QRIS</th>
                                <th class="px-4 py-3 text-right">Seharusnya</th>
                                <th class="px-4 py-3 text-right">Dihitung</th>
                                <th class="px-4 py-3 text-right">Selisih</th>
                                <th class="px-4 py-3 text-right">Setoran</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="shift in shifts.data" :key="shift.id">
                                <td class="px-4 py-3">
                                    {{ shift.branch_code }} ·
                                    {{ shift.branch_name }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ shift.cashier_name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ shift.window_key }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ formatDateTime(shift.opened_at) }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ formatDateTime(shift.closed_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money :value="shift.opening_cash" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money
                                        :value="shift.cash_sales_total ?? 0"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money
                                        :value="shift.qris_sales_total ?? 0"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money :value="shift.expected_cash ?? 0" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money :value="shift.counted_cash ?? 0" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money :value="shift.variance ?? 0" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Money :value="shift.cash_deposited ?? 0" />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <Badge
                                            :tone="
                                                shift.status === 'open'
                                                    ? 'success'
                                                    : shift.status ===
                                                        'auto_closed_unreconciled'
                                                      ? 'warning'
                                                      : 'neutral'
                                            "
                                        >
                                            {{
                                                shift.status === 'open'
                                                    ? 'Terbuka'
                                                    : shift.status ===
                                                        'auto_closed_unreconciled'
                                                      ? 'Perlu rekon'
                                                      : 'Ditutup'
                                            }}
                                        </Badge>
                                        <Link
                                            :href="`/admin/shifts/${shift.id}`"
                                            class="text-sm font-semibold text-brand underline"
                                            >Detail</Link
                                        >
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="shifts.prev_page_url || shifts.next_page_url"
                    class="flex justify-between text-sm"
                >
                    <Link
                        v-if="shifts.prev_page_url"
                        :href="shifts.prev_page_url"
                        class="font-semibold text-brand"
                        >Sebelumnya</Link
                    >
                    <span v-else />
                    <Link
                        v-if="shifts.next_page_url"
                        :href="shifts.next_page_url"
                        class="font-semibold text-brand"
                        >Berikutnya</Link
                    >
                </div>
            </template>

            <ShiftDailySummaryTable
                v-if="view === 'daily' && daily"
                :rows="daily.rows as never"
            />
        </div>
    </StaffLayout>
</template>
