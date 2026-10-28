<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type VariantOption = {
    id: number;
    name: string;
};

type ProductRow = {
    id: number;
    name: string;
    variants: VariantOption[];
};

type ModifierRow = {
    id: number;
    name: string;
};

type IngredientRow = {
    id: number;
    name: string;
    unit_code: string | null;
};

type RecipeItemRow = {
    ingredient_id: number;
    ingredient: string | null;
    unit_code: string | null;
    quantity: number;
};

type RecipeRow = {
    id: number;
    target: string;
    items: RecipeItemRow[];
};

type ItemForm = {
    ingredient_id: number | null;
    quantity: number;
};

const props = defineProps<{
    products: ProductRow[];
    modifiers: ModifierRow[];
    ingredients: IngredientRow[];
    recipes: RecipeRow[];
}>();

const page = usePage();

const form = useForm({
    recipeable_type: 'product',
    recipeable_id: null as number | null,
    items: [{ ingredient_id: null, quantity: 1 }] as ItemForm[],
});

const editingId = ref<number | null>(null);
const deletingRecipe = ref<RecipeRow | null>(null);
const deleteForm = useForm({});

const typeOptions = [
    { value: 'product', label: 'Produk' },
    { value: 'product_variant', label: 'Varian produk' },
    { value: 'modifier', label: 'Modifier' },
];

const targetOptions = computed(() => {
    if (form.recipeable_type === 'product_variant') {
        return props.products.flatMap((product) =>
            product.variants.map((variant) => ({
                value: variant.id,
                label: `${product.name} — ${variant.name}`,
            })),
        );
    }

    if (form.recipeable_type === 'modifier') {
        return props.modifiers.map((modifier) => ({
            value: modifier.id,
            label: modifier.name,
        }));
    }

    return props.products.map((product) => ({
        value: product.id,
        label: product.name,
    }));
});

const ingredientOptions = computed(() =>
    props.ingredients.map((ingredient) => ({
        value: ingredient.id,
        label: ingredient.unit_code
            ? `${ingredient.name} (${ingredient.unit_code})`
            : ingredient.name,
    })),
);

watch(
    () => form.recipeable_type,
    () => {
        form.recipeable_id = null;
    },
);

function unitLabel(ingredientId: number | null): string | null {
    return (
        props.ingredients.find((ingredient) => ingredient.id === ingredientId)
            ?.unit_code ?? null
    );
}

function rowError(index: number, field: string): string | undefined {
    const errors = form.errors as Record<string, string>;

    return (
        errors[`items_${index}_${field}`] ?? errors[`items.${index}.${field}`]
    );
}

function addRow(): void {
    form.items.push({ ingredient_id: null, quantity: 1 });
}

function removeRow(index: number): void {
    form.items.splice(index, 1);
}

function startEdit(recipe: RecipeRow): void {
    editingId.value = recipe.id;
    form.clearErrors();
    form.recipeable_id = null;
    form.items = recipe.items.map((item) => ({
        ingredient_id: item.ingredient_id,
        quantity: item.quantity,
    }));
}

function cancelEdit(): void {
    editingId.value = null;
    form.reset();
    form.recipeable_type = 'product';
}

function submit(): void {
    if (editingId.value !== null) {
        form.transform((data) => ({ items: data.items })).patch(
            `/admin/stock/recipes/${editingId.value}`,
            {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
                onFinish: () => form.transform((data) => data),
            },
        );

        return;
    }

    form.post('/admin/stock/recipes', {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

function confirmDelete(): void {
    if (!deletingRecipe.value) {
        return;
    }

    deleteForm.delete(`/admin/stock/recipes/${deletingRecipe.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingRecipe.value = null;
        },
    });
}
</script>

<template>
    <Head title="Resep" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Resep</h1>
                    <p class="mt-1 text-sm text-muted">
                        Atur bahan yang terpakai untuk setiap produk, varian,
                        dan modifier.
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
                        href="/admin/stock/units"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Satuan
                    </Link>
                    <Link
                        href="/admin/stock/transfers"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Transfer
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
                <h2 class="font-semibold text-ink">
                    {{ editingId === null ? 'Tambah resep' : 'Ubah resep' }}
                </h2>

                <div
                    v-if="props.ingredients.length === 0"
                    class="mt-4 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
                >
                    Bahan belum tersedia. Tambahkan bahan terlebih dahulu di
                    halaman Bahan.
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div
                        v-if="editingId === null"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <Select
                            v-model="form.recipeable_type"
                            label="Jenis target"
                            :options="typeOptions"
                            :error="form.errors.recipeable_type"
                            required
                        />
                        <Select
                            v-model="form.recipeable_id"
                            label="Target"
                            :options="targetOptions"
                            placeholder="Pilih target"
                            :error="form.errors.recipeable_id"
                            required
                        />
                    </div>

                    <div class="space-y-3">
                        <span class="block text-sm font-medium text-ink">
                            Bahan per porsi
                        </span>
                        <div
                            v-for="(item, index) in form.items"
                            :key="index"
                            class="grid gap-3 sm:grid-cols-[1fr_160px_auto]"
                        >
                            <Select
                                v-model="item.ingredient_id"
                                :label="index === 0 ? 'Bahan' : undefined"
                                :options="ingredientOptions"
                                placeholder="Pilih bahan"
                                :error="rowError(index, 'ingredient_id')"
                                required
                            />
                            <div class="flex items-end gap-2">
                                <Input
                                    v-model="item.quantity"
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    :label="index === 0 ? 'Jumlah' : undefined"
                                    :error="rowError(index, 'quantity')"
                                    required
                                    class="grow"
                                />
                                <span
                                    class="mb-3.5 min-w-10 text-sm font-semibold text-muted"
                                >
                                    {{ unitLabel(item.ingredient_id) ?? '—' }}
                                </span>
                            </div>
                            <div class="flex items-end">
                                <button
                                    v-if="form.items.length > 1"
                                    type="button"
                                    class="min-h-12 rounded-xl border border-line px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                                    @click="removeRow(index)"
                                >
                                    Hapus baris
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="form.errors.items"
                            class="text-sm text-danger"
                        >
                            {{ form.errors.items }}
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addRow"
                        >
                            Tambah baris
                        </Button>
                    </div>

                    <div class="flex flex-wrap gap-2">
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
                                    : editingId === null
                                      ? 'Simpan resep'
                                      : 'Simpan perubahan'
                            }}
                        </Button>
                        <Button
                            v-if="editingId !== null"
                            type="button"
                            variant="secondary"
                            @click="cancelEdit"
                        >
                            Batal mengubah
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Daftar resep</h2>
                    <Badge tone="info">{{ props.recipes.length }} resep</Badge>
                </div>

                <div v-if="props.recipes.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada resep"
                        description="Tambahkan resep pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Target</th>
                                <th class="px-5 py-3">Bahan</th>
                                <th class="px-5 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="recipe in props.recipes"
                                :key="recipe.id"
                            >
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ recipe.target }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    <ul class="space-y-1">
                                        <li
                                            v-for="item in recipe.items"
                                            :key="item.ingredient_id"
                                        >
                                            {{ item.ingredient ?? '—' }}
                                            <span class="tabular-nums">
                                                {{ item.quantity }}
                                                {{ item.unit_code ?? '' }}
                                            </span>
                                        </li>
                                    </ul>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl border border-line px-3 text-sm font-semibold text-ink transition hover:bg-canvas"
                                            @click="startEdit(recipe)"
                                        >
                                            Ubah
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl border border-danger/30 px-3 text-sm font-semibold text-danger transition hover:bg-danger/10"
                                            @click="deletingRecipe = recipe"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :open="deletingRecipe !== null"
            title="Hapus resep"
            message="Resep akan dihapus; pesanan lama tidak berubah, pesanan baru akan berhenti menguras bahan."
            confirm-label="Hapus resep"
            cancel-label="Batal"
            danger
            @confirm="confirmDelete"
            @cancel="deletingRecipe = null"
        />
    </StaffLayout>
</template>
