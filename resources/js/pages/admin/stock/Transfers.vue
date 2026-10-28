<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/datetime';

type BranchRow = {
    id: number;
    name: string;
};

type IngredientRow = {
    id: number;
    name: string;
    unit_code: string | null;
};

type TransferItemRow = {
    id: number;
    ingredient: string | null;
    unit_code: string | null;
    quantity: number;
    received_quantity: number | null;
    discrepancy_reason: string | null;
};

type TransferStatus = 'draft' | 'dispatched' | 'received' | 'cancelled';

type TransferRow = {
    id: number;
    status: TransferStatus;
    origin_name: string | null;
    destination_name: string | null;
    created_by_name: string | null;
    created_at: string | null;
    dispatched_at: string | null;
    received_at: string | null;
    cancel_reason: string | null;
    items: TransferItemRow[];
};

type ItemForm = {
    ingredient_id: number | null;
    quantity: number;
};

const props = defineProps<{
    transfers: TransferRow[];
    branches: BranchRow[];
    ingredients: IngredientRow[];
}>();

const page = usePage();

const form = useForm({
    origin_branch_id: null as number | null,
    destination_branch_id: null as number | null,
    items: [{ ingredient_id: null, quantity: 1 }] as ItemForm[],
});

const activeTransferId = ref<number | null>(null);

function createDispatchForm() {
    return useForm({});
}

function createReceiveForm(transfer: TransferRow) {
    return useForm({
        received_items: transfer.items.map((item) => ({
            item_id: item.id,
            received_quantity: item.received_quantity ?? item.quantity,
            discrepancy_reason: '',
        })),
    });
}

function createCancelForm() {
    return useForm({ cancel_reason: '' });
}

const dispatchForms = reactive<
    Record<number, ReturnType<typeof createDispatchForm>>
>({});
const receiveForms = reactive<
    Record<number, ReturnType<typeof createReceiveForm>>
>({});
const cancelForms = reactive<
    Record<number, ReturnType<typeof createCancelForm>>
>({});

const statusMeta: Record<
    TransferStatus,
    {
        label: string;
        tone: 'neutral' | 'success' | 'danger' | 'warning' | 'info';
    }
> = {
    draft: { label: 'Draft', tone: 'neutral' },
    dispatched: { label: 'In-transit', tone: 'info' },
    received: { label: 'Diterima', tone: 'success' },
    cancelled: { label: 'Dibatalkan', tone: 'danger' },
};

const branchOptions = computed(() =>
    props.branches.map((branch) => ({
        value: branch.id,
        label: branch.name,
    })),
);

const ingredientOptions = computed(() =>
    props.ingredients.map((ingredient) => ({
        value: ingredient.id,
        label: ingredient.unit_code
            ? `${ingredient.name} (${ingredient.unit_code})`
            : ingredient.name,
    })),
);

function dispatchFormFor(transfer: TransferRow) {
    if (!dispatchForms[transfer.id]) {
        dispatchForms[transfer.id] = createDispatchForm();
    }

    return dispatchForms[transfer.id];
}

function receiveFormFor(transfer: TransferRow) {
    if (!receiveForms[transfer.id]) {
        receiveForms[transfer.id] = createReceiveForm(transfer);
    }

    return receiveForms[transfer.id];
}

function cancelFormFor(transfer: TransferRow) {
    if (!cancelForms[transfer.id]) {
        cancelForms[transfer.id] = createCancelForm();
    }

    return cancelForms[transfer.id];
}

// Inertia forms share one error bag; errors hanya tampil di kartu yang terakhir dikirim.
function cardError(
    transfer: TransferRow,
    errors: Record<string, string | undefined>,
    key: string,
): string | undefined {
    if (activeTransferId.value !== transfer.id) {
        return undefined;
    }

    return errors[key];
}

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

function submit(): void {
    form.post('/admin/stock/transfers', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function submitDispatch(transfer: TransferRow): void {
    activeTransferId.value = transfer.id;
    dispatchFormFor(transfer).patch(
        `/admin/stock/transfers/${transfer.id}/dispatch`,
        { preserveScroll: true },
    );
}

function submitReceive(transfer: TransferRow): void {
    activeTransferId.value = transfer.id;
    receiveFormFor(transfer).patch(
        `/admin/stock/transfers/${transfer.id}/receive`,
        { preserveScroll: true },
    );
}

function submitCancel(transfer: TransferRow): void {
    activeTransferId.value = transfer.id;
    cancelFormFor(transfer).patch(
        `/admin/stock/transfers/${transfer.id}/cancel`,
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Transfer antar cabang" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">
                        Transfer antar cabang
                    </h1>
                    <p class="mt-1 text-sm text-muted">
                        Pindahkan stok antar cabang. Stok tujuan bertambah saat
                        transfer diterima.
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
                        Pergerakan stok
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
                <h2 class="font-semibold text-ink">Transfer baru</h2>

                <div
                    v-if="props.ingredients.length === 0"
                    class="mt-4 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
                >
                    Bahan belum tersedia. Tambahkan bahan terlebih dahulu di
                    halaman Bahan.
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Select
                            v-model="form.origin_branch_id"
                            label="Cabang asal"
                            :options="branchOptions"
                            placeholder="Pilih cabang asal"
                            :error="form.errors.origin_branch_id"
                            required
                        />
                        <Select
                            v-model="form.destination_branch_id"
                            label="Cabang tujuan"
                            :options="branchOptions"
                            placeholder="Pilih cabang tujuan"
                            :error="form.errors.destination_branch_id"
                            required
                        />
                    </div>

                    <div class="space-y-3">
                        <span class="block text-sm font-medium text-ink">
                            Bahan yang dikirim
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
                                    : 'Buat transfer'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Daftar transfer</h2>
                    <Badge tone="info"
                        >{{ props.transfers.length }} transfer</Badge
                    >
                </div>

                <div v-if="props.transfers.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada transfer"
                        description="Buat transfer pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="divide-y divide-line">
                    <article
                        v-for="transfer in props.transfers"
                        :key="transfer.id"
                        class="px-5 py-4"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h3 class="font-semibold text-ink">
                                {{ transfer.origin_name ?? '—' }} →
                                {{ transfer.destination_name ?? '—' }}
                            </h3>
                            <Badge :tone="statusMeta[transfer.status].tone">
                                {{ statusMeta[transfer.status].label }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-sm text-muted">
                            Dibuat oleh
                            {{ transfer.created_by_name ?? '—' }} ·
                            {{ formatDateTime(transfer.created_at) }}
                        </p>
                        <p
                            v-if="transfer.status === 'cancelled'"
                            class="mt-1 text-sm text-danger"
                        >
                            Alasan pembatalan:
                            {{ transfer.cancel_reason ?? '—' }}
                        </p>

                        <div class="mt-3 overflow-x-auto">
                            <table
                                v-if="transfer.status === 'dispatched'"
                                class="w-full min-w-[720px] text-left text-sm"
                            >
                                <thead
                                    class="bg-canvas text-xs tracking-wide text-muted uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Bahan</th>
                                        <th class="px-4 py-3">Satuan</th>
                                        <th class="px-4 py-3 text-right">
                                            Dikirim
                                        </th>
                                        <th class="px-4 py-3">
                                            Jumlah diterima
                                        </th>
                                        <th class="px-4 py-3">
                                            Alasan selisih
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="(item, index) in transfer.items"
                                        :key="item.id"
                                    >
                                        <td
                                            class="px-4 py-3 font-medium text-ink"
                                        >
                                            {{ item.ingredient ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted">
                                            {{ item.unit_code ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-ink tabular-nums"
                                        >
                                            {{ item.quantity }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <Input
                                                v-model="
                                                    receiveFormFor(transfer)
                                                        .received_items[index]
                                                        .received_quantity
                                                "
                                                type="number"
                                                min="0"
                                                step="1"
                                                inputmode="numeric"
                                                :error="
                                                    cardError(
                                                        transfer,
                                                        receiveFormFor(transfer)
                                                            .errors,
                                                        `received_items.${index}.received_quantity`,
                                                    )
                                                "
                                                required
                                            />
                                        </td>
                                        <td class="px-4 py-3">
                                            <Input
                                                v-model="
                                                    receiveFormFor(transfer)
                                                        .received_items[index]
                                                        .discrepancy_reason
                                                "
                                                placeholder="Wajib jika kurang dari dikirim"
                                                :error="
                                                    cardError(
                                                        transfer,
                                                        receiveFormFor(transfer)
                                                            .errors,
                                                        `received_items.${index}.discrepancy_reason`,
                                                    )
                                                "
                                                :required="
                                                    Number(
                                                        receiveFormFor(transfer)
                                                            .received_items[
                                                            index
                                                        ].received_quantity,
                                                    ) < item.quantity
                                                "
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <table
                                v-else-if="transfer.status === 'received'"
                                class="w-full min-w-[640px] text-left text-sm"
                            >
                                <thead
                                    class="bg-canvas text-xs tracking-wide text-muted uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Bahan</th>
                                        <th class="px-4 py-3">Satuan</th>
                                        <th class="px-4 py-3 text-right">
                                            Dikirim
                                        </th>
                                        <th class="px-4 py-3 text-right">
                                            Jumlah diterima
                                        </th>
                                        <th class="px-4 py-3">Alasan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="item in transfer.items"
                                        :key="item.id"
                                    >
                                        <td
                                            class="px-4 py-3 font-medium text-ink"
                                        >
                                            {{ item.ingredient ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted">
                                            {{ item.unit_code ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-muted tabular-nums"
                                        >
                                            {{ item.quantity }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-ink tabular-nums"
                                        >
                                            {{ item.received_quantity ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted">
                                            {{ item.discrepancy_reason || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <table
                                v-else
                                class="w-full min-w-[480px] text-left text-sm"
                            >
                                <thead
                                    class="bg-canvas text-xs tracking-wide text-muted uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Bahan</th>
                                        <th class="px-4 py-3">Satuan</th>
                                        <th class="px-4 py-3 text-right">
                                            Jumlah
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="item in transfer.items"
                                        :key="item.id"
                                    >
                                        <td
                                            class="px-4 py-3 font-medium text-ink"
                                        >
                                            {{ item.ingredient ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted">
                                            {{ item.unit_code ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-ink tabular-nums"
                                        >
                                            {{ item.quantity }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <template v-if="transfer.status === 'draft'">
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button
                                    :disabled="
                                        dispatchFormFor(transfer).processing
                                    "
                                    @click="submitDispatch(transfer)"
                                >
                                    {{
                                        dispatchFormFor(transfer).processing
                                            ? 'Mengirim...'
                                            : 'Kirim'
                                    }}
                                </Button>
                            </div>
                            <div
                                v-if="
                                    cardError(
                                        transfer,
                                        dispatchFormFor(transfer).errors,
                                        'stock',
                                    )
                                "
                                class="mt-2 text-sm text-danger"
                            >
                                {{
                                    cardError(
                                        transfer,
                                        dispatchFormFor(transfer).errors,
                                        'stock',
                                    )
                                }}
                            </div>
                            <div
                                v-if="
                                    cardError(
                                        transfer,
                                        dispatchFormFor(transfer).errors,
                                        'status',
                                    )
                                "
                                class="mt-2 text-sm text-danger"
                            >
                                {{
                                    cardError(
                                        transfer,
                                        dispatchFormFor(transfer).errors,
                                        'status',
                                    )
                                }}
                            </div>
                            <form
                                class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]"
                                @submit.prevent="submitCancel(transfer)"
                            >
                                <Input
                                    v-model="
                                        cancelFormFor(transfer).cancel_reason
                                    "
                                    placeholder="Alasan pembatalan"
                                    :error="
                                        cardError(
                                            transfer,
                                            cancelFormFor(transfer).errors,
                                            'cancel_reason',
                                        )
                                    "
                                    required
                                />
                                <Button
                                    variant="danger"
                                    type="submit"
                                    :disabled="
                                        cancelFormFor(transfer).processing
                                    "
                                >
                                    Batal
                                </Button>
                            </form>
                        </template>

                        <template v-else-if="transfer.status === 'dispatched'">
                            <div
                                v-if="
                                    cardError(
                                        transfer,
                                        receiveFormFor(transfer).errors,
                                        'received_items',
                                    )
                                "
                                class="mt-2 text-sm text-danger"
                            >
                                {{
                                    cardError(
                                        transfer,
                                        receiveFormFor(transfer).errors,
                                        'received_items',
                                    )
                                }}
                            </div>
                            <div
                                v-if="
                                    cardError(
                                        transfer,
                                        receiveFormFor(transfer).errors,
                                        'status',
                                    )
                                "
                                class="mt-2 text-sm text-danger"
                            >
                                {{
                                    cardError(
                                        transfer,
                                        receiveFormFor(transfer).errors,
                                        'status',
                                    )
                                }}
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button
                                    :disabled="
                                        receiveFormFor(transfer).processing
                                    "
                                    @click="submitReceive(transfer)"
                                >
                                    {{
                                        receiveFormFor(transfer).processing
                                            ? 'Menerima...'
                                            : 'Terima'
                                    }}
                                </Button>
                            </div>
                        </template>
                    </article>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
