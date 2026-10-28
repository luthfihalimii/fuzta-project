<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';

type AuditRow = {
    id: number;
    action: string;
    actor_name: string | null;
    branch_code: string | null;
    target_type: string;
    target_id: number;
    reason: string | null;
    before: Record<string, unknown>;
    after: Record<string, unknown>;
    created_at: string | null;
};

type PaginatedLogs = {
    data: AuditRow[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

defineProps<{
    logs: PaginatedLogs;
}>();

function hasDetails(values: Record<string, unknown>): boolean {
    return Object.keys(values).length > 0;
}
</script>

<template>
    <Head title="Audit" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Audit</h1>
                    <p class="mt-1 text-sm text-muted">
                        Riwayat tindakan penting. Hanya dapat dibaca.
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
                        href="/admin/reports/stock"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Laporan stok
                    </Link>
                </div>
            </div>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Log audit</h2>
                    <p class="text-sm text-muted">
                        <template v-if="logs.from !== null">
                            {{ logs.from }}–{{ logs.to }} dari
                        </template>
                        {{ logs.total }} entri
                    </p>
                </div>

                <div v-if="logs.data.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada log audit"
                        description="Tindakan penting akan muncul di sini."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Waktu</th>
                                <th class="px-5 py-3">Aktor</th>
                                <th class="px-5 py-3">Aksi</th>
                                <th class="px-5 py-3">Target</th>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Alasan</th>
                                <th class="px-5 py-3">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="log in logs.data" :key="log.id">
                                <td
                                    class="px-5 py-4 whitespace-nowrap text-muted"
                                >
                                    {{ formatDateTime(log.created_at) }}
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ log.actor_name ?? 'Sistem' }}
                                </td>
                                <td
                                    class="px-5 py-4 font-mono text-xs text-ink"
                                >
                                    {{ log.action }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ log.target_type }} #{{ log.target_id }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ log.branch_code ?? '—' }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ log.reason ?? '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <details
                                        v-if="
                                            hasDetails(log.before) ||
                                            hasDetails(log.after)
                                        "
                                        class="group"
                                    >
                                        <summary
                                            class="cursor-pointer text-sm font-semibold text-brand"
                                        >
                                            Lihat
                                        </summary>
                                        <div class="mt-2 space-y-2">
                                            <div v-if="hasDetails(log.before)">
                                                <p
                                                    class="text-xs font-semibold text-muted"
                                                >
                                                    Sebelum
                                                </p>
                                                <pre
                                                    class="mt-1 max-h-48 overflow-auto rounded-lg bg-canvas p-2 text-xs text-ink"
                                                    >{{
                                                        JSON.stringify(
                                                            log.before,
                                                            null,
                                                            2,
                                                        )
                                                    }}</pre>
                                            </div>
                                            <div v-if="hasDetails(log.after)">
                                                <p
                                                    class="text-xs font-semibold text-muted"
                                                >
                                                    Sesudah
                                                </p>
                                                <pre
                                                    class="mt-1 max-h-48 overflow-auto rounded-lg bg-canvas p-2 text-xs text-ink"
                                                    >{{
                                                        JSON.stringify(
                                                            log.after,
                                                            null,
                                                            2,
                                                        )
                                                    }}</pre>
                                            </div>
                                        </div>
                                    </details>
                                    <span v-else class="text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="logs.prev_page_url || logs.next_page_url"
                    class="flex items-center justify-between border-t border-line px-5 py-4"
                >
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Sebelumnya
                    </Link>
                    <span v-else></span>

                    <Link
                        v-if="logs.next_page_url"
                        :href="logs.next_page_url"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Berikutnya
                    </Link>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
