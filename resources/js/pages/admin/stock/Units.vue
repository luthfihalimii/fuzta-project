<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type UnitRow = {
    id: number;
    code: string;
    name: string;
};

type ConversionRow = {
    id: number;
    from_unit_id: number;
    to_unit_id: number;
    from_code: string | null;
    to_code: string | null;
    from_quantity: number;
    to_quantity: number;
};

const props = defineProps<{
    units: UnitRow[];
    conversions: ConversionRow[];
}>();

const page = usePage();

const unitForm = useForm({
    name: '',
    code: '',
});

const editingUnitId = ref<number | null>(null);
const editUnitForm = useForm({ name: '' });

const deletingUnit = ref<UnitRow | null>(null);
const deleteUnitForm = useForm({});
const deleteUnitError = computed(
    () => (deleteUnitForm.errors as Record<string, string>).unit,
);

const conversionForm = useForm({
    from_unit_id: null as number | null,
    to_unit_id: null as number | null,
    from_quantity: 1,
    to_quantity: 1000,
});

const deleteConversionForm = useForm({});

const unitOptions = computed(() =>
    props.units.map((unit) => ({
        value: unit.id,
        label: `${unit.code} — ${unit.name}`,
    })),
);

const conversionExample = computed(() => {
    const from = props.units.find(
        (unit) => unit.id === conversionForm.from_unit_id,
    );
    const to = props.units.find(
        (unit) => unit.id === conversionForm.to_unit_id,
    );

    if (
        !from ||
        !to ||
        !(conversionForm.from_quantity > 0) ||
        !(conversionForm.to_quantity > 0)
    ) {
        return null;
    }

    const ratio = conversionForm.to_quantity / conversionForm.from_quantity;
    const ratioText = Number.isInteger(ratio)
        ? String(ratio)
        : String(Number(ratio.toFixed(3)));

    return `1 ${from.code} = ${ratioText} ${to.code}`;
});

function submitUnit(): void {
    unitForm.post('/admin/stock/units', {
        preserveScroll: true,
        onSuccess: () => unitForm.reset(),
    });
}

function startEditUnit(unit: UnitRow): void {
    editingUnitId.value = unit.id;
    editUnitForm.clearErrors();
    editUnitForm.name = unit.name;
    deleteUnitForm.clearErrors();
}

function cancelEditUnit(): void {
    editingUnitId.value = null;
    editUnitForm.reset();
}

function submitEditUnit(): void {
    if (editingUnitId.value === null) {
        return;
    }

    editUnitForm.patch(`/admin/stock/units/${editingUnitId.value}`, {
        preserveScroll: true,
        onSuccess: () => cancelEditUnit(),
    });
}

function confirmDeleteUnit(): void {
    if (!deletingUnit.value) {
        return;
    }

    deleteUnitForm.delete(`/admin/stock/units/${deletingUnit.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingUnit.value = null;
        },
    });
}

function submitConversion(): void {
    conversionForm.post('/admin/stock/unit-conversions', {
        preserveScroll: true,
        onSuccess: () => conversionForm.reset('from_quantity', 'to_quantity'),
    });
}

function deleteConversion(conversion: ConversionRow): void {
    deleteConversionForm.delete(
        `/admin/stock/unit-conversions/${conversion.id}`,
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head title="Satuan & konversi" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">
                        Satuan &amp; konversi
                    </h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelola satuan bahan dan aturan konversi antar satuan.
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
                        href="/admin/stock/recipes"
                        class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Resep
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

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Tambah satuan</h2>
                </div>

                <form
                    class="grid gap-4 p-5 sm:grid-cols-[1fr_160px_auto] sm:items-end"
                    @submit.prevent="submitUnit"
                >
                    <Input
                        v-model="unitForm.name"
                        label="Nama satuan"
                        placeholder="Kilogram"
                        :error="unitForm.errors.name"
                        required
                    />
                    <Input
                        v-model="unitForm.code"
                        label="Kode"
                        placeholder="kg"
                        :error="unitForm.errors.code"
                        required
                    />
                    <Button type="submit" :disabled="unitForm.processing">
                        {{
                            unitForm.processing
                                ? 'Menyimpan...'
                                : 'Simpan satuan'
                        }}
                    </Button>
                </form>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-4"
                >
                    <h3 class="font-semibold text-ink">Daftar satuan</h3>
                    <Badge tone="info">{{ props.units.length }} satuan</Badge>
                </div>

                <div
                    v-if="props.units.length === 0"
                    class="border-t border-line p-5"
                >
                    <EmptyState
                        title="Belum ada satuan"
                        description="Tambahkan satuan pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto border-t border-line">
                    <div
                        v-if="deleteUnitError"
                        class="border-b border-line px-5 py-3 text-sm text-danger"
                    >
                        {{ deleteUnitError }}
                    </div>
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Kode</th>
                                <th class="px-5 py-3">Nama</th>
                                <th class="px-5 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="unit in props.units" :key="unit.id">
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ unit.code }}
                                </td>
                                <td class="px-5 py-4">
                                    <template v-if="editingUnitId === unit.id">
                                        <Input
                                            v-model="editUnitForm.name"
                                            :error="editUnitForm.errors.name"
                                            required
                                        />
                                    </template>
                                    <span v-else class="text-muted">{{
                                        unit.name
                                    }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <div
                                        v-if="editingUnitId === unit.id"
                                        class="flex flex-wrap gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl bg-brand px-3 text-sm font-semibold text-white transition hover:bg-brand/90 disabled:opacity-60"
                                            :disabled="editUnitForm.processing"
                                            @click="submitEditUnit"
                                        >
                                            {{
                                                editUnitForm.processing
                                                    ? 'Menyimpan...'
                                                    : 'Simpan'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl border border-line px-3 text-sm font-semibold text-ink transition hover:bg-canvas"
                                            @click="cancelEditUnit"
                                        >
                                            Batal
                                        </button>
                                    </div>
                                    <div v-else class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl border border-line px-3 text-sm font-semibold text-ink transition hover:bg-canvas"
                                            @click="startEditUnit(unit)"
                                        >
                                            Ubah
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-10 rounded-xl border border-danger/30 px-3 text-sm font-semibold text-danger transition hover:bg-danger/10"
                                            @click="deletingUnit = unit"
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

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Tambah konversi</h2>
                </div>

                <div
                    v-if="props.units.length < 2"
                    class="mx-5 mt-5 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
                >
                    Tambahkan minimal dua satuan terlebih dahulu untuk membuat
                    konversi.
                </div>

                <form
                    class="grid gap-4 p-5 sm:grid-cols-2"
                    @submit.prevent="submitConversion"
                >
                    <Select
                        v-model="conversionForm.from_unit_id"
                        label="Dari satuan"
                        :options="unitOptions"
                        placeholder="Pilih satuan"
                        :error="conversionForm.errors.from_unit_id"
                        required
                    />
                    <Select
                        v-model="conversionForm.to_unit_id"
                        label="Ke satuan"
                        :options="unitOptions"
                        placeholder="Pilih satuan"
                        :error="conversionForm.errors.to_unit_id"
                        required
                    />
                    <Input
                        v-model="conversionForm.from_quantity"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        label="Jumlah dari"
                        :error="conversionForm.errors.from_quantity"
                        required
                    />
                    <Input
                        v-model="conversionForm.to_quantity"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        label="Jumlah menjadi"
                        :error="conversionForm.errors.to_quantity"
                        required
                    />

                    <p
                        v-if="conversionExample"
                        class="text-sm text-muted sm:col-span-2"
                    >
                        Contoh:
                        <span class="font-semibold text-ink tabular-nums">{{
                            conversionExample
                        }}</span>
                    </p>

                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            :disabled="
                                conversionForm.processing ||
                                props.units.length < 2
                            "
                        >
                            {{
                                conversionForm.processing
                                    ? 'Menyimpan...'
                                    : 'Simpan konversi'
                            }}
                        </Button>
                    </div>
                </form>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-4"
                >
                    <h3 class="font-semibold text-ink">Daftar konversi</h3>
                    <Badge tone="info"
                        >{{ props.conversions.length }} konversi</Badge
                    >
                </div>

                <div
                    v-if="props.conversions.length === 0"
                    class="border-t border-line p-5"
                >
                    <EmptyState
                        title="Belum ada konversi"
                        description="Tambahkan konversi pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto border-t border-line">
                    <table class="w-full min-w-[480px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Konversi</th>
                                <th class="px-5 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="conversion in props.conversions"
                                :key="conversion.id"
                            >
                                <td
                                    class="px-5 py-4 font-medium text-ink tabular-nums"
                                >
                                    {{ conversion.from_quantity }}
                                    {{ conversion.from_code ?? '—' }}
                                    <span class="text-muted">→</span>
                                    {{ conversion.to_quantity }}
                                    {{ conversion.to_code ?? '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-xl border border-danger/30 px-3 text-sm font-semibold text-danger transition hover:bg-danger/10 disabled:opacity-60"
                                        :disabled="
                                            deleteConversionForm.processing
                                        "
                                        @click="deleteConversion(conversion)"
                                    >
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :open="deletingUnit !== null"
            title="Hapus satuan"
            message="Satuan akan dihapus. Satuan yang masih dipakai bahan, pergerakan stok, atau konversi tidak dapat dihapus."
            confirm-label="Hapus satuan"
            cancel-label="Batal"
            danger
            @confirm="confirmDeleteUnit"
            @cancel="deletingUnit = null"
        />
    </StaffLayout>
</template>
