<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import type { BranchOption, BranchSummary } from '@/lib/order';

type IngredientRow = {
    ingredient_id: number;
    name: string;
    unit: string | null;
    quantity: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
};

type CountRow = {
    id: number;
    submitted_by_name: string | null;
    note: string | null;
    items_count: number;
    created_at: string | null;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    ingredients: IngredientRow[];
    counts: CountRow[];
}>();

const page = usePage();
const branchSelect = ref<number | null>(props.branch?.id ?? null);
const counted = ref<Record<number, string>>({});

const form = useForm({
    branch_id: props.branch?.id ?? null,
    note: '',
    counted_items: [] as Array<{
        ingredient_id: number;
        counted_quantity: number;
    }>,
});

const enteredItems = computed(() =>
    props.ingredients
        .filter((ingredient) => {
            const value = counted.value[ingredient.ingredient_id];

            return value !== undefined && value !== '';
        })
        .map((ingredient) => ({
            ingredient_id: ingredient.ingredient_id,
            counted_quantity: Number(counted.value[ingredient.ingredient_id]),
        })),
);

function changeBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/admin/stock/counts',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

function submit(): void {
    if (props.branch === null) {
        return;
    }

    if (enteredItems.value.length === 0) {
        form.setError('counted_items', 'Isi minimal satu bahan.');
        return;
    }

    form.branch_id = props.branch.id;
    form.counted_items = enteredItems.value;
    form.post('/admin/stock/counts', {
        preserveScroll: true,
        onSuccess: () => {
            counted.value = {};
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Opname Stok" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Opname stok</h1>
                    <p class="mt-1 text-sm text-muted">
                        Hitung stok fisik. Selisih otomatis menjadi koreksi
                        ledger.
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
                        href="/admin/stock/movements"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Pergerakan
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
                <label for="count-branch" class="text-sm font-medium text-ink">
                    Cabang
                </label>
                <select
                    id="count-branch"
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
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2 class="font-semibold text-ink">Hitung fisik</h2>
                            <p class="mt-1 text-sm text-muted">
                                Kosongkan bahan yang tidak dihitung.
                            </p>
                        </div>
                        <Badge tone="info">
                            {{ enteredItems.length }} bahan diisi
                        </Badge>
                    </div>

                    <div class="mt-4">
                        <Input
                            v-model="form.note"
                            label="Catatan (opsional)"
                            :error="form.errors.note"
                        />
                    </div>

                    <div v-if="props.ingredients.length === 0" class="mt-4">
                        <EmptyState
                            title="Belum ada bahan"
                            description="Tambahkan bahan sebelum melakukan opname."
                        />
                    </div>

                    <div v-else class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left text-sm">
                            <thead
                                class="bg-canvas text-xs tracking-wide text-muted uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3">Bahan</th>
                                    <th class="px-4 py-3 text-right">Sistem</th>
                                    <th class="px-4 py-3">Hitung fisik</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="ingredient in props.ingredients"
                                    :key="ingredient.ingredient_id"
                                >
                                    <td class="px-4 py-3 font-medium text-ink">
                                        {{ ingredient.name }}
                                        <span
                                            class="text-xs font-normal text-muted"
                                        >
                                            {{ ingredient.unit }}
                                        </span>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right text-muted tabular-nums"
                                    >
                                        {{ ingredient.quantity }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <input
                                            v-model="
                                                counted[
                                                    ingredient.ingredient_id
                                                ]
                                            "
                                            type="number"
                                            min="0"
                                            step="1"
                                            inputmode="numeric"
                                            :aria-label="`Hitung ${ingredient.name}`"
                                            class="min-h-12 w-32 rounded-xl border border-line bg-white px-3 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge
                                            :tone="
                                                ingredient.is_low_stock
                                                    ? 'warning'
                                                    : 'success'
                                            "
                                        >
                                            {{
                                                ingredient.is_low_stock
                                                    ? 'Menipis'
                                                    : 'Aman'
                                            }}
                                        </Badge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-if="form.errors.counted_items"
                        class="mt-3 text-sm text-danger"
                    >
                        {{ form.errors.counted_items }}
                    </p>

                    <div class="mt-4">
                        <Button
                            type="button"
                            :disabled="
                                form.processing ||
                                props.ingredients.length === 0
                            "
                            @click="submit"
                        >
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : 'Simpan opname'
                            }}
                        </Button>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                    >
                        <h2 class="font-semibold text-ink">Riwayat opname</h2>
                        <Badge tone="info"
                            >{{ props.counts.length }} opname</Badge
                        >
                    </div>

                    <div v-if="props.counts.length === 0" class="p-5">
                        <EmptyState
                            title="Belum ada opname"
                            description="Simpan hitung fisik pertama untuk cabang ini."
                        />
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead
                                class="bg-canvas text-xs tracking-wide text-muted uppercase"
                            >
                                <tr>
                                    <th class="px-5 py-3">Waktu</th>
                                    <th class="px-5 py-3">Oleh</th>
                                    <th class="px-5 py-3">Catatan</th>
                                    <th class="px-5 py-3 text-right">Bahan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="count in props.counts"
                                    :key="count.id"
                                >
                                    <td
                                        class="px-5 py-4 whitespace-nowrap text-muted"
                                    >
                                        {{ formatDateTime(count.created_at) }}
                                    </td>
                                    <td class="px-5 py-4 text-ink">
                                        {{ count.submitted_by_name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-muted">
                                        {{ count.note || '—' }}
                                    </td>
                                    <td
                                        class="px-5 py-4 text-right text-ink tabular-nums"
                                    >
                                        {{ count.items_count }}
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
