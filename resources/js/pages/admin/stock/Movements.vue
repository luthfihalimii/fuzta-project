<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import type { BranchOption, BranchSummary } from '@/lib/order';

type IngredientOption = {
    id: number;
    name: string;
    unit: string | null;
};

type MovementRow = {
    id: number;
    ingredient_id: number;
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
    branch: BranchSummary | null;
    branches: BranchOption[];
    ingredients: IngredientOption[];
    movements: MovementRow[];
}>();

const page = usePage();
const branchSelect = ref<number | null>(props.branch?.id ?? null);

const typeOptions = [
    { value: 'receipt', label: 'Penerimaan' },
    { value: 'opening', label: 'Stok awal' },
    { value: 'adjustment', label: 'Koreksi' },
];

const ingredientOptions = props.ingredients.map((ingredient) => ({
    value: ingredient.id,
    label: `${ingredient.name}${ingredient.unit ? ` (${ingredient.unit})` : ''}`,
}));

const form = useForm({
    branch_id: props.branch?.id ?? null,
    ingredient_id: null as number | null,
    quantity: 1,
    type: 'receipt',
    reason: '',
});

function changeBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/admin/stock/movements',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

function submit(): void {
    form.branch_id = props.branch?.id ?? null;
    form.post('/admin/stock/movements', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('quantity', 'reason');
            form.quantity = 1;
        },
    });
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
    <Head title="Pergerakan Stok" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Pergerakan stok</h1>
                    <p class="mt-1 text-sm text-muted">
                        Catat stok awal, penerimaan, dan koreksi. Ledger tidak
                        dapat diubah atau dihapus.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/stock/ingredients"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Bahan
                    </Link>
                    <Link
                        href="/admin/stock/counts"
                        class="inline-flex min-h-12 items-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand/90"
                    >
                        Opname
                    </Link>
                    <Link
                        href="/admin/stock/units"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Satuan
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

            <div class="flex flex-wrap items-center gap-3">
                <label
                    for="movement-branch"
                    class="text-sm font-medium text-ink"
                >
                    Cabang
                </label>
                <select
                    id="movement-branch"
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

            <div
                v-if="!props.branch"
                class="rounded-2xl border border-dashed border-line bg-white p-8 text-center"
            >
                <p class="font-semibold text-ink">Cabang belum tersedia.</p>
                <p class="mt-1 text-sm text-muted">
                    Aktifkan cabang terlebih dahulu.
                </p>
            </div>

            <template v-else>
                <section class="rounded-2xl border border-line bg-white p-5">
                    <h2 class="font-semibold text-ink">Catat pergerakan</h2>
                    <p class="mt-1 text-sm text-muted">
                        Gunakan angka negatif hanya untuk koreksi pengurangan.
                    </p>

                    <form
                        class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
                        @submit.prevent="submit"
                    >
                        <Select
                            v-model="form.type"
                            label="Jenis"
                            :options="typeOptions"
                            :error="form.errors.type"
                            required
                        />
                        <div class="lg:col-span-2">
                            <Select
                                v-model="form.ingredient_id"
                                label="Bahan"
                                :options="ingredientOptions"
                                placeholder="Pilih bahan"
                                :error="form.errors.ingredient_id"
                                required
                            />
                        </div>
                        <Input
                            v-model="form.quantity"
                            type="number"
                            step="1"
                            inputmode="numeric"
                            label="Jumlah"
                            :error="form.errors.quantity"
                            required
                        />
                        <Input
                            v-model="form.reason"
                            label="Alasan"
                            :error="form.errors.reason"
                            required
                        />

                        <div class="sm:col-span-2 lg:col-span-5">
                            <Button
                                type="submit"
                                :disabled="
                                    form.processing ||
                                    props.ingredients.length === 0
                                "
                            >
                                {{
                                    form.processing
                                        ? 'Menyimpan...'
                                        : 'Simpan pergerakan'
                                }}
                            </Button>
                        </div>
                    </form>

                    <p
                        v-if="props.ingredients.length === 0"
                        class="mt-3 text-sm text-warning"
                    >
                        Tambahkan bahan terlebih dahulu sebelum mencatat stok.
                    </p>
                </section>

                <section class="rounded-2xl border border-line bg-white">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                    >
                        <h2 class="font-semibold text-ink">Ledger terbaru</h2>
                        <Badge tone="info"
                            >{{ props.movements.length }} baris</Badge
                        >
                    </div>

                    <div v-if="props.movements.length === 0" class="p-5">
                        <EmptyState
                            title="Belum ada pergerakan"
                            description="Catat stok awal atau penerimaan pertama."
                        />
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead
                                class="bg-canvas text-xs tracking-wide text-muted uppercase"
                            >
                                <tr>
                                    <th class="px-5 py-3">Waktu</th>
                                    <th class="px-5 py-3">Bahan</th>
                                    <th class="px-5 py-3">Jenis</th>
                                    <th class="px-5 py-3 text-right">Jumlah</th>
                                    <th class="px-5 py-3">Alasan</th>
                                    <th class="px-5 py-3">Oleh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="movement in props.movements"
                                    :key="movement.id"
                                >
                                    <td
                                        class="px-5 py-4 whitespace-nowrap text-muted"
                                    >
                                        {{
                                            formatDateTime(movement.created_at)
                                        }}
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
            </template>
        </div>
    </StaffLayout>
</template>
