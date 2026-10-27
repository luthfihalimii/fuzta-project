<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type UnitRow = {
    id: number;
    code: string;
    name: string;
};

type BalanceRow = {
    branch_id: number;
    branch_code: string | null;
    branch_name: string | null;
    quantity: number;
    is_low_stock: boolean;
};

type IngredientRow = {
    id: number;
    name: string;
    base_unit_id: number;
    unit: {
        id: number | null;
        code: string | null;
        name: string | null;
    };
    low_stock_threshold: number;
    total_quantity: number;
    balances: BalanceRow[];
};

const props = defineProps<{
    units: UnitRow[];
    ingredients: IngredientRow[];
}>();

const page = usePage();

const form = useForm({
    name: '',
    base_unit_id: null as number | null,
    low_stock_threshold: 0,
});

const unitOptions = props.units.map((unit) => ({
    value: unit.id,
    label: `${unit.code} — ${unit.name}`,
}));

function submit(): void {
    form.post('/admin/stock/ingredients', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Bahan" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Bahan</h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelola bahan, satuan dasar, dan ambang stok menipis.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/stock/movements"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Pergerakan
                    </Link>
                    <Link
                        href="/admin/stock/counts"
                        class="inline-flex min-h-12 items-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand/90"
                    >
                        Opname
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

            <section class="rounded-2xl border border-line bg-white p-5">
                <h2 class="font-semibold text-ink">Tambah bahan</h2>

                <div
                    v-if="props.units.length === 0"
                    class="mt-4 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
                >
                    Satuan belum tersedia. Jalankan seeder satuan terlebih
                    dahulu.
                </div>

                <form class="mt-4 grid gap-4 sm:grid-cols-3" @submit.prevent="submit">
                    <Input
                        v-model="form.name"
                        label="Nama bahan"
                        :error="form.errors.name"
                        required
                    />
                    <Select
                        v-model="form.base_unit_id"
                        label="Satuan dasar"
                        :options="unitOptions"
                        placeholder="Pilih satuan"
                        :error="form.errors.base_unit_id"
                        required
                    />
                    <Input
                        v-model="form.low_stock_threshold"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        label="Ambang stok menipis"
                        :error="form.errors.low_stock_threshold"
                        required
                    />

                    <div class="sm:col-span-3">
                        <Button
                            type="submit"
                            :disabled="form.processing || props.units.length === 0"
                        >
                            {{ form.processing ? 'Menyimpan...' : 'Tambah bahan' }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Daftar bahan</h2>
                    <Badge tone="info">{{ props.ingredients.length }} bahan</Badge>
                </div>

                <div v-if="props.ingredients.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada bahan"
                        description="Tambahkan bahan pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3">Satuan</th>
                                <th class="px-5 py-3">Ambang</th>
                                <th class="px-5 py-3">Stok per cabang</th>
                                <th class="px-5 py-3">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="ingredient in props.ingredients" :key="ingredient.id">
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ ingredient.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ ingredient.unit.code ?? '—' }}
                                </td>
                                <td class="px-5 py-4 tabular-nums text-muted">
                                    {{ ingredient.low_stock_threshold }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="balance in ingredient.balances"
                                            :key="balance.branch_id"
                                            class="inline-flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-semibold"
                                            :class="
                                                balance.is_low_stock
                                                    ? 'border-warning/30 bg-warning/10 text-warning'
                                                    : 'border-line bg-canvas text-muted'
                                            "
                                        >
                                            {{ balance.branch_code ?? '—' }}
                                            <span class="tabular-nums text-ink">
                                                {{ balance.quantity }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="ingredient.balances.length === 0"
                                            class="text-muted"
                                        >
                                            Belum ada stok
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 tabular-nums font-semibold text-ink">
                                    {{ ingredient.total_quantity }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
