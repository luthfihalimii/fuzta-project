<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Button.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import { formatRupiah } from '@/lib/money';
import type { BranchOption, BranchSummary } from '@/lib/order';

type SupplierOption = {
    id: number;
    name: string;
};

type UnitOption = {
    id: number;
    code: string;
    name: string;
};

type IngredientOption = {
    id: number;
    name: string;
    base_unit_id: number;
    unit: string | null;
};

type FormRow = {
    ingredient_id: number | null;
    unit_id: number | null;
    quantity: string | number;
    unit_price: string | number;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    suppliers: SupplierOption[];
    units: UnitOption[];
    ingredients: IngredientOption[];
    defaultDate: string;
    submitUrl: string;
}>();

const page = usePage();

function emptyRow(): FormRow {
    return {
        ingredient_id: null,
        unit_id: null,
        quantity: 1,
        unit_price: 0,
    };
}

function toDateString(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

const now = new Date();
const maxDate = toDateString(now);
const minDate = toDateString(
    new Date(now.getFullYear(), now.getMonth(), now.getDate() - 90),
);

const branchId = ref<number | null>(props.branch?.id ?? null);
const supplierId = ref<number | null>(null);
const receivedAt = ref(props.defaultDate);
const reference = ref('');
const idempotencyKey = ref<string>(crypto.randomUUID());
const rows = ref<FormRow[]>([emptyRow()]);
const submitting = ref(false);
const localError = ref('');

const branchOptions = props.branches.map((branch) => ({
    value: branch.id,
    label: `${branch.code} — ${branch.name}`,
}));

const supplierOptions = props.suppliers.map((supplier) => ({
    value: supplier.id,
    label: supplier.name,
}));

const unitOptions = props.units.map((unit) => ({
    value: unit.id,
    label: `${unit.code} — ${unit.name}`,
}));

const ingredientOptions = props.ingredients.map((ingredient) => ({
    value: ingredient.id,
    label: `${ingredient.name}${ingredient.unit ? ` (${ingredient.unit})` : ''}`,
}));

const errors = computed(() => page.props.errors as Record<string, string>);

const filledRows = computed(() =>
    rows.value.filter(
        (row) => row.ingredient_id !== null && Number(row.quantity) >= 1,
    ),
);

const grandTotal = computed(() =>
    filledRows.value.reduce(
        (sum, row) => sum + Number(row.quantity) * Number(row.unit_price),
        0,
    ),
);

const canSubmit = computed(
    () =>
        !submitting.value &&
        branchId.value !== null &&
        supplierId.value !== null &&
        filledRows.value.length > 0,
);

function setIngredient(row: FormRow, value: string | number | null): void {
    const id = value === null ? null : Number(value);
    row.ingredient_id = id;

    if (id !== null) {
        row.unit_id =
            props.ingredients.find((ingredient) => ingredient.id === id)
                ?.base_unit_id ?? null;
    }
}

function addRow(): void {
    rows.value.push(emptyRow());
}

function removeRow(index: number): void {
    if (rows.value.length <= 1) {
        return;
    }

    rows.value.splice(index, 1);
}

function rowTotal(row: FormRow): string {
    return formatRupiah(Number(row.quantity) * Number(row.unit_price));
}

function submit(): void {
    localError.value = '';

    if (supplierId.value === null) {
        localError.value = 'Pilih pemasok terlebih dahulu.';
        return;
    }

    if (filledRows.value.length === 0) {
        localError.value = 'Isi minimal satu baris bahan dengan jumlah ≥ 1.';
        return;
    }

    router.post(
        props.submitUrl,
        {
            branch_id: branchId.value,
            supplier_id: supplierId.value,
            received_at: receivedAt.value,
            reference: reference.value || null,
            idempotency_key: idempotencyKey.value,
            items: filledRows.value.map((row) => ({
                ingredient_id: row.ingredient_id,
                unit_id: row.unit_id,
                quantity: Math.trunc(Number(row.quantity)),
                unit_price: Math.trunc(Number(row.unit_price)),
            })),
        },
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
            onSuccess: () => {
                rows.value = [emptyRow()];
                reference.value = '';
                idempotencyKey.value = crypto.randomUUID();
            },
        },
    );
}
</script>

<template>
    <section class="rounded-2xl border border-line bg-white p-5">
        <h2 class="font-semibold text-ink">Catat penerimaan barang</h2>
        <p class="mt-1 text-sm text-muted">
            Total dihitung ulang oleh server saat disimpan.
        </p>

        <form class="mt-4 space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Select
                    v-if="props.canChooseBranch && branchOptions.length > 0"
                    v-model="branchId"
                    label="Cabang"
                    :options="branchOptions"
                    :error="errors.branch_id"
                    required
                />
                <Select
                    v-model="supplierId"
                    label="Pemasok"
                    :options="supplierOptions"
                    placeholder="Pilih pemasok"
                    :error="errors.supplier_id"
                    required
                />
                <Input
                    v-model="receivedAt"
                    type="date"
                    label="Tanggal terima"
                    :min="minDate"
                    :max="maxDate"
                    :error="errors.received_at"
                    required
                />
                <Input
                    v-model="reference"
                    label="No. referensi (opsional)"
                    placeholder="Contoh: INV-2026-001"
                    :error="errors.reference"
                />
            </div>

            <div class="space-y-3">
                <div
                    v-for="(row, index) in rows"
                    :key="index"
                    class="grid items-end gap-3 rounded-xl border border-line bg-canvas/50 p-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]"
                >
                    <Select
                        :model-value="row.ingredient_id"
                        label="Bahan"
                        :options="ingredientOptions"
                        placeholder="Pilih bahan"
                        :error="errors[`items.${index}.ingredient_id`]"
                        required
                        @update:model-value="setIngredient(row, $event ?? null)"
                    />
                    <Select
                        v-model="row.unit_id"
                        label="Satuan"
                        :options="unitOptions"
                        placeholder="Satuan"
                        :error="errors[`items.${index}.unit_id`]"
                        required
                    />
                    <Input
                        v-model="row.quantity"
                        type="number"
                        :min="1"
                        :step="1"
                        inputmode="numeric"
                        label="Jumlah"
                        :error="errors[`items.${index}.quantity`]"
                        required
                    />
                    <Input
                        v-model="row.unit_price"
                        type="number"
                        :min="0"
                        :step="1000"
                        inputmode="numeric"
                        label="Harga satuan"
                        :error="errors[`items.${index}.unit_price`]"
                        required
                    />
                    <p
                        class="min-h-12 text-right text-sm leading-[3rem] font-semibold whitespace-nowrap text-ink tabular-nums"
                    >
                        {{ rowTotal(row) }}
                    </p>
                    <button
                        type="button"
                        :disabled="rows.length <= 1"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line px-3 text-sm font-semibold text-muted transition hover:bg-white hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
                        @click="removeRow(index)"
                    >
                        Hapus
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="props.ingredients.length === 0"
                    @click="addRow"
                >
                    Tambah baris
                </Button>
                <p class="text-sm text-muted">
                    Total sementara:
                    <span class="text-base font-bold text-ink tabular-nums">
                        {{ formatRupiah(grandTotal) }}
                    </span>
                </p>
            </div>

            <p
                v-if="props.ingredients.length === 0"
                class="text-sm text-warning"
            >
                Tambahkan bahan terlebih dahulu sebelum menerima barang.
            </p>
            <p
                v-if="localError"
                class="text-sm font-semibold text-danger"
                role="alert"
            >
                {{ localError }}
            </p>
            <p v-if="errors.items" class="text-sm text-danger">
                {{ errors.items }}
            </p>

            <Button type="submit" :disabled="!canSubmit">
                {{ submitting ? 'Menyimpan...' : 'Simpan penerimaan' }}
            </Button>
        </form>
    </section>
</template>
