<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Money from '@/components/Money.vue';
import PaymentSheet from '@/components/pos/PaymentSheet.vue';
import ReceiptPreview from '@/components/pos/ReceiptPreview.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { HttpError, postJson } from '@/lib/http';
import type { BranchOption, BranchSummary, PosOrder } from '@/lib/order';
import { formatSignedRupiah } from '@/lib/money';

type PosVariant = {
    id: number;
    name: string;
    price: number;
};

type PosModifier = {
    id: number;
    name: string;
    price_delta: number;
};

type PosModifierGroup = {
    id: number;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    modifiers: PosModifier[];
};

type PosProduct = {
    id: number;
    name: string;
    description: string | null;
    category_id: number;
    category_name: string | null;
    base_price: number;
    variants: PosVariant[];
    modifier_groups: PosModifierGroup[];
};

type PosCategory = {
    id: number;
    name: string;
};

type CartLine = {
    key: string;
    product: PosProduct;
    variant: PosVariant | null;
    modifiers: PosModifier[];
    quantity: number;
    note: string;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    categories: PosCategory[];
    products: PosProduct[];
    ordersUrl: string;
}>();

const page = usePage();

const branchSelect = ref<number | null>(
    props.branch?.id ?? props.branches[0]?.id ?? null,
);
const selectedCategoryId = ref<number | null>(null);
const search = ref('');
const cart = ref<CartLine[]>([]);
const cartOpen = ref(false);
const selectedProduct = ref<PosProduct | null>(null);
const draftVariantId = ref<number | null>(null);
const draftModifierIds = ref<number[]>([]);
const draftQuantity = ref(1);
const draftNote = ref('');
const draftError = ref('');
const channel = ref<'dine_in' | 'takeaway'>('takeaway');
const tableNumber = ref('');
const customerName = ref('');
const orderNote = ref('');
const submittingOrder = ref(false);
const orderError = ref('');
const createdOrder = ref<PosOrder | null>(null);
const paymentOpen = ref(false);
const receiptOpen = ref(false);
const paidOrder = ref<PosOrder | null>(null);
const successMessage = ref('');
const pendingKey = ref<string | null>(null);

const filteredProducts = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.products.filter((product) => {
        if (
            selectedCategoryId.value !== null &&
            product.category_id !== selectedCategoryId.value
        ) {
            return false;
        }

        if (query === '') {
            return true;
        }

        return (
            product.name.toLowerCase().includes(query) ||
            (product.description ?? '').toLowerCase().includes(query)
        );
    });
});

const cartCount = computed(() =>
    cart.value.reduce((total, line) => total + line.quantity, 0),
);

const cartTotal = computed(() =>
    cart.value.reduce(
        (total, line) => total + lineUnitPrice(line) * line.quantity,
        0,
    ),
);

const draftUnitPrice = computed(() => {
    const product = selectedProduct.value;

    if (!product) {
        return 0;
    }

    const variant = product.variants.find(
        (item) => item.id === draftVariantId.value,
    );

    let price = variant?.price ?? product.base_price;

    for (const modifierId of draftModifierIds.value) {
        for (const group of product.modifier_groups) {
            const modifier = group.modifiers.find(
                (item) => item.id === modifierId,
            );

            if (modifier) {
                price += modifier.price_delta;
                break;
            }
        }
    }

    return price;
});

function newKey(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function startingPrice(product: PosProduct): number {
    if (product.variants.length === 0) {
        return product.base_price;
    }

    return Math.min(...product.variants.map((variant) => variant.price));
}

function lineUnitPrice(line: CartLine): number {
    const base = line.variant?.price ?? line.product.base_price;

    return (
        base +
        line.modifiers.reduce(
            (total, modifier) => total + modifier.price_delta,
            0,
        )
    );
}

function lineDescription(line: CartLine): string {
    return [line.variant?.name, ...line.modifiers.map((item) => item.name)]
        .filter((value): value is string => Boolean(value))
        .join(' · ');
}

function openProduct(product: PosProduct): void {
    if (product.variants.length === 0 && product.modifier_groups.length === 0) {
        cart.value.push({
            key: newKey(),
            product,
            variant: null,
            modifiers: [],
            quantity: 1,
            note: '',
        });
        cartOpen.value = true;
        successMessage.value = '';
        orderError.value = '';

        return;
    }

    selectedProduct.value = product;
    draftVariantId.value = product.variants[0]?.id ?? null;
    draftModifierIds.value = [];
    draftQuantity.value = 1;
    draftNote.value = '';
    draftError.value = '';
}

function selectedCount(group: PosModifierGroup): number {
    return group.modifiers.filter((modifier) =>
        draftModifierIds.value.includes(modifier.id),
    ).length;
}

function isModifierDisabled(
    group: PosModifierGroup,
    modifier: PosModifier,
): boolean {
    if (draftModifierIds.value.includes(modifier.id)) {
        return false;
    }

    if (group.max_select <= 0) {
        return true;
    }

    return selectedCount(group) >= group.max_select;
}

function toggleModifier(group: PosModifierGroup, modifier: PosModifier): void {
    if (draftModifierIds.value.includes(modifier.id)) {
        draftModifierIds.value = draftModifierIds.value.filter(
            (id) => id !== modifier.id,
        );

        return;
    }

    if (group.max_select <= 0) {
        return;
    }

    if (selectedCount(group) >= group.max_select) {
        if (group.max_select === 1) {
            const groupIds = new Set(group.modifiers.map((item) => item.id));

            draftModifierIds.value = [
                ...draftModifierIds.value.filter((id) => !groupIds.has(id)),
                modifier.id,
            ];
        }

        return;
    }

    draftModifierIds.value = [...draftModifierIds.value, modifier.id];
}

function groupHint(group: PosModifierGroup): string {
    if (group.is_required && group.min_select === group.max_select) {
        return `Wajib pilih ${group.min_select}`;
    }

    if (group.is_required) {
        return `Wajib, ${group.min_select}–${group.max_select}`;
    }

    return `Maks ${group.max_select}`;
}

function validateDraft(): string {
    const product = selectedProduct.value;

    if (!product) {
        return '';
    }

    if (product.variants.length > 0 && draftVariantId.value === null) {
        return 'Pilih varian produk.';
    }

    for (const group of product.modifier_groups) {
        const count = selectedCount(group);

        if (group.is_required && count < group.min_select) {
            return `Pilih minimal ${group.min_select} ${group.name}.`;
        }

        if (count > group.max_select) {
            return `Maksimal ${group.max_select} ${group.name}.`;
        }
    }

    if (draftQuantity.value < 1 || draftQuantity.value > 99) {
        return 'Jumlah harus antara 1 dan 99.';
    }

    return '';
}

function addDraftToCart(): void {
    const product = selectedProduct.value;

    if (!product) {
        return;
    }

    const error = validateDraft();

    if (error !== '') {
        draftError.value = error;

        return;
    }

    const variant =
        product.variants.find((item) => item.id === draftVariantId.value) ??
        null;

    const modifiers = product.modifier_groups
        .flatMap((group) => group.modifiers)
        .filter((modifier) => draftModifierIds.value.includes(modifier.id));

    cart.value.push({
        key: newKey(),
        product,
        variant,
        modifiers,
        quantity: draftQuantity.value,
        note: draftNote.value.trim(),
    });

    selectedProduct.value = null;
    cartOpen.value = true;
    successMessage.value = '';
    orderError.value = '';
}

function increment(line: CartLine): void {
    line.quantity = Math.min(99, line.quantity + 1);
}

function decrement(line: CartLine): void {
    line.quantity = Math.max(1, line.quantity - 1);
}

function removeLine(key: string): void {
    cart.value = cart.value.filter((line) => line.key !== key);
}

function closePayment(): void {
    paymentOpen.value = false;

    if (createdOrder.value) {
        successMessage.value = `Pesanan ${createdOrder.value.order_number} menunggu pembayaran.`;
    }
}

function reopenPayment(): void {
    if (createdOrder.value) {
        paymentOpen.value = true;
    }
}

function onPaid(order: PosOrder): void {
    paymentOpen.value = false;
    createdOrder.value = null;
    paidOrder.value = order;
    receiptOpen.value = true;
    successMessage.value = 'Pembayaran berhasil.';
}

function startNewOrder(): void {
    receiptOpen.value = false;
    paidOrder.value = null;
    successMessage.value = '';
    orderError.value = '';
}

async function submitOrder(): Promise<void> {
    if (cart.value.length === 0 || submittingOrder.value) {
        return;
    }

    if (!props.branch || !props.branch.is_active) {
        orderError.value = 'Cabang tidak aktif. Hubungi pemilik.';

        return;
    }

    submittingOrder.value = true;
    orderError.value = '';
    successMessage.value = '';

    const key = pendingKey.value ?? newKey();
    pendingKey.value = key;

    try {
        const response = await postJson<{ order: PosOrder }>('/pos/orders', {
            branch_id: props.branch.id,
            channel: channel.value,
            table_number:
                channel.value === 'dine_in'
                    ? tableNumber.value.trim() || null
                    : null,
            customer_name: customerName.value.trim() || null,
            note: orderNote.value.trim() || null,
            items: cart.value.map((line) => ({
                product_id: line.product.id,
                variant_id: line.variant?.id ?? null,
                modifier_ids: line.modifiers.map((modifier) => modifier.id),
                quantity: line.quantity,
                note: line.note || null,
            })),
            idempotency_key: key,
        });

        pendingKey.value = null;
        createdOrder.value = response.order;
        cart.value = [];
        cartOpen.value = false;
        tableNumber.value = '';
        customerName.value = '';
        orderNote.value = '';

        if (response.order.payment_status === 'paid') {
            paidOrder.value = response.order;
            receiptOpen.value = true;
            successMessage.value = 'Pembayaran berhasil.';
        } else {
            paymentOpen.value = true;
            successMessage.value = 'Pesanan dibuat. Selesaikan pembayaran.';
        }
    } catch (error) {
        orderError.value =
            error instanceof HttpError
                ? error.firstError()
                : 'Pesanan gagal dibuat. Coba lagi.';
    } finally {
        submittingOrder.value = false;
    }
}

function switchBranch(): void {
    if (branchSelect.value === null) {
        return;
    }

    router.get(
        '/pos',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true },
    );
}
</script>

<template>
    <Head title="POS" />

    <StaffLayout>
        <div class="space-y-5 pb-24 lg:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">POS</h1>
                    <p class="mt-1 text-sm text-muted">
                        <template v-if="props.branch">
                            {{ props.branch.name }} · {{ props.branch.code }}
                        </template>
                        <template v-else-if="props.canChooseBranch">
                            Pilih cabang untuk mulai menerima pesanan.
                        </template>
                        <template v-else>
                            Akun Anda belum terhubung ke cabang. Hubungi
                            pemilik.
                        </template>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-if="props.canChooseBranch"
                        v-model="branchSelect"
                        class="min-h-12 rounded-xl border border-line bg-white px-3 text-sm font-semibold text-ink"
                        aria-label="Pilih cabang"
                        @change="switchBranch"
                    >
                        <option
                            v-for="branch in props.branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.code }} — {{ branch.name }}
                        </option>
                    </select>

                    <Link
                        :href="props.ordersUrl"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Pesanan
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
            <div
                v-if="successMessage"
                class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
            >
                {{ successMessage }}
            </div>
            <div
                v-if="orderError"
                class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
            >
                {{ orderError }}
            </div>

            <div
                v-if="props.branch && !props.branch.is_active"
                class="rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
            >
                Cabang ini nonaktif. Pesanan baru tidak dapat dibuat.
            </div>

            <div
                v-if="props.products.length > 0"
                class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="flex gap-2 overflow-x-auto pb-1">
                    <button
                        type="button"
                        class="min-h-12 shrink-0 rounded-xl px-4 text-sm font-semibold transition"
                        :class="
                            selectedCategoryId === null
                                ? 'bg-brand text-white'
                                : 'border border-line bg-white text-muted hover:text-ink'
                        "
                        :aria-pressed="selectedCategoryId === null"
                        @click="selectedCategoryId = null"
                    >
                        Semua
                    </button>
                    <button
                        v-for="category in props.categories"
                        :key="category.id"
                        type="button"
                        class="min-h-12 shrink-0 rounded-xl px-4 text-sm font-semibold transition"
                        :class="
                            selectedCategoryId === category.id
                                ? 'bg-brand text-white'
                                : 'border border-line bg-white text-muted hover:text-ink'
                        "
                        :aria-pressed="selectedCategoryId === category.id"
                        @click="selectedCategoryId = category.id"
                    >
                        {{ category.name }}
                    </button>
                </div>

                <div class="w-full lg:max-w-xs">
                    <Input
                        v-model="search"
                        type="search"
                        label="Cari menu"
                        placeholder="Nama atau deskripsi…"
                    />
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_360px] lg:items-start">
                <section class="min-w-0">
                    <EmptyState
                        v-if="props.products.length === 0"
                        title="Belum ada menu tersedia"
                        description="Produk aktif yang tersedia di cabang ini akan muncul di sini. Atur katalog terlebih dahulu."
                    >
                        <Link
                            v-if="page.props.auth.user?.role === 'owner'"
                            href="/admin/catalog/products"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white"
                        >
                            Buka katalog
                        </Link>
                    </EmptyState>

                    <EmptyState
                        v-else-if="filteredProducts.length === 0"
                        title="Menu tidak ditemukan"
                        description="Coba kata kunci atau kategori lain."
                    />

                    <div
                        v-else
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4"
                    >
                        <button
                            v-for="product in filteredProducts"
                            :key="product.id"
                            type="button"
                            class="flex min-h-32 flex-col rounded-2xl border border-line bg-white p-4 text-left transition hover:border-brand/40 hover:shadow-sm active:scale-[0.99]"
                            @click="openProduct(product)"
                        >
                            <span class="text-sm font-semibold text-ink">
                                {{ product.name }}
                            </span>
                            <span class="mt-0.5 text-xs text-muted">
                                {{ product.category_name ?? 'Tanpa kategori' }}
                            </span>
                            <span
                                class="mt-auto pt-3 text-sm font-bold text-brand"
                            >
                                <span
                                    v-if="product.variants.length > 0"
                                    class="block text-[10px] font-medium tracking-wide text-muted uppercase"
                                >
                                    Mulai dari
                                </span>
                                <Money :value="startingPrice(product)" />
                            </span>
                            <span
                                v-if="product.modifier_groups.length > 0"
                                class="mt-1 text-[11px] text-muted"
                            >
                                Bisa custom
                            </span>
                        </button>
                    </div>
                </section>

                <aside
                    class="fixed inset-x-0 bottom-0 z-40 max-h-[88vh] overflow-y-auto rounded-t-2xl border border-line bg-white p-4 shadow-2xl transition-transform duration-200 lg:sticky lg:top-20 lg:z-0 lg:max-h-[calc(100vh-6rem)] lg:translate-y-0 lg:rounded-2xl lg:border lg:shadow-sm"
                    :class="
                        cartOpen
                            ? 'translate-y-0'
                            : 'translate-y-full lg:translate-y-0'
                    "
                    aria-label="Keranjang pesanan"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-bold text-ink">
                            Pesanan
                            <span class="text-sm font-medium text-muted">
                                ({{ cartCount }})
                            </span>
                        </h2>
                        <button
                            type="button"
                            class="min-h-12 rounded-xl px-3 text-sm font-semibold text-muted hover:bg-canvas lg:hidden"
                            @click="cartOpen = false"
                        >
                            Tutup
                        </button>
                    </div>

                    <div
                        v-if="createdOrder"
                        class="mt-3 rounded-xl border border-warning/20 bg-warning/10 p-3 text-sm text-warning"
                    >
                        <p class="font-semibold">
                            Pesanan {{ createdOrder.order_number }} belum
                            dibayar.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button @click="reopenPayment">
                                Bayar sekarang
                            </Button>
                            <Link
                                :href="props.ordersUrl"
                                class="inline-flex min-h-12 items-center justify-center rounded-xl border border-warning/30 bg-white px-4 text-sm font-semibold text-warning"
                            >
                                Lihat pesanan
                            </Link>
                        </div>
                    </div>

                    <div v-if="cart.length === 0" class="mt-4">
                        <EmptyState
                            title="Keranjang kosong"
                            description="Pilih menu untuk mulai membuat pesanan."
                        />
                    </div>

                    <div v-else class="mt-3 divide-y divide-line">
                        <div v-for="line in cart" :key="line.key" class="py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink">
                                        {{ line.product.name }}
                                    </p>
                                    <p
                                        v-if="lineDescription(line)"
                                        class="text-xs text-muted"
                                    >
                                        {{ lineDescription(line) }}
                                    </p>
                                    <p
                                        v-if="line.note"
                                        class="text-xs text-muted italic"
                                    >
                                        {{ line.note }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="min-h-12 shrink-0 rounded-lg px-2 text-xs font-semibold text-danger hover:bg-danger/10"
                                    @click="removeLine(line.key)"
                                >
                                    Hapus
                                </button>
                            </div>

                            <div
                                class="mt-2 flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="h-12 w-12 rounded-lg border border-line text-lg font-semibold text-ink disabled:opacity-40"
                                        :disabled="line.quantity <= 1"
                                        aria-label="Kurangi jumlah"
                                        @click="decrement(line)"
                                    >
                                        −
                                    </button>
                                    <span
                                        class="w-8 text-center text-sm font-semibold"
                                    >
                                        {{ line.quantity }}
                                    </span>
                                    <button
                                        type="button"
                                        class="h-12 w-12 rounded-lg border border-line text-lg font-semibold text-ink disabled:opacity-40"
                                        :disabled="line.quantity >= 99"
                                        aria-label="Tambah jumlah"
                                        @click="increment(line)"
                                    >
                                        +
                                    </button>
                                </div>
                                <p class="text-sm font-semibold text-ink">
                                    <Money
                                        :value="
                                            lineUnitPrice(line) * line.quantity
                                        "
                                    />
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="cart.length > 0" class="mt-4 space-y-3">
                        <div>
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Tipe pesanan
                            </span>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="min-h-12 rounded-xl border px-3 text-sm font-semibold transition"
                                    :class="
                                        channel === 'dine_in'
                                            ? 'border-brand bg-brand text-white'
                                            : 'border-line bg-white text-ink'
                                    "
                                    :aria-pressed="channel === 'dine_in'"
                                    @click="channel = 'dine_in'"
                                >
                                    Dine-in
                                </button>
                                <button
                                    type="button"
                                    class="min-h-12 rounded-xl border px-3 text-sm font-semibold transition"
                                    :class="
                                        channel === 'takeaway'
                                            ? 'border-brand bg-brand text-white'
                                            : 'border-line bg-white text-ink'
                                    "
                                    :aria-pressed="channel === 'takeaway'"
                                    @click="channel = 'takeaway'"
                                >
                                    Takeaway
                                </button>
                            </div>
                        </div>

                        <Input
                            v-if="channel === 'dine_in'"
                            v-model="tableNumber"
                            label="Nomor meja (opsional)"
                            placeholder="Contoh: A-12"
                        />

                        <Input
                            v-model="customerName"
                            label="Nama pelanggan (opsional)"
                            placeholder="Nama pelanggan"
                        />

                        <label class="block">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Catatan pesanan (opsional)
                            </span>
                            <textarea
                                v-model="orderNote"
                                rows="2"
                                class="w-full rounded-xl border border-line bg-white px-3.5 py-3 text-base text-ink outline-none placeholder:text-muted/70 focus:border-brand focus:ring-2 focus:ring-brand/20"
                                placeholder="Contoh: tanpa saus"
                            />
                        </label>

                        <div
                            class="flex items-center justify-between border-t border-line pt-3"
                        >
                            <span class="text-sm font-semibold text-ink">
                                Total
                            </span>
                            <span class="text-xl font-bold text-ink">
                                <Money :value="cartTotal" />
                            </span>
                        </div>
                        <p class="text-xs text-muted">
                            Total akhir dihitung ulang oleh server saat pesanan
                            dibuat.
                        </p>

                        <div
                            v-if="orderError"
                            class="rounded-xl border border-danger/20 bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ orderError }}
                        </div>

                        <Button
                            class="w-full"
                            :disabled="submittingOrder || cart.length === 0"
                            @click="submitOrder"
                        >
                            {{
                                submittingOrder
                                    ? 'Membuat pesanan…'
                                    : 'Buat pesanan'
                            }}
                        </Button>
                    </div>
                </aside>
            </div>
        </div>

        <div
            v-if="cartCount > 0 || createdOrder"
            class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white p-3 lg:hidden"
        >
            <button
                type="button"
                class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl bg-brand px-4 text-sm font-semibold text-white"
                @click="cartOpen = true"
            >
                <span>
                    {{
                        createdOrder && cartCount === 0
                            ? 'Pesanan belum dibayar'
                            : `${cartCount} item`
                    }}
                </span>
                <span class="flex items-center gap-2">
                    <Money
                        :value="
                            createdOrder && cartCount === 0
                                ? createdOrder.total
                                : cartTotal
                        "
                    />
                    <span aria-hidden="true">›</span>
                </span>
            </button>
        </div>

        <div
            v-if="cartOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            @click="cartOpen = false"
        />

        <Teleport to="body">
            <div
                v-if="selectedProduct"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                :aria-label="`Atur ${selectedProduct.name}`"
                @click.self="selectedProduct = null"
            >
                <div
                    class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-xl"
                >
                    <div
                        class="sticky top-0 flex items-start justify-between gap-3 border-b border-line bg-white px-5 py-4"
                    >
                        <div>
                            <h2 class="text-lg font-bold text-ink">
                                {{ selectedProduct.name }}
                            </h2>
                            <p
                                v-if="selectedProduct.description"
                                class="mt-0.5 text-sm text-muted"
                            >
                                {{ selectedProduct.description }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="min-h-12 rounded-xl px-3 text-sm font-semibold text-muted hover:bg-canvas"
                            @click="selectedProduct = null"
                        >
                            Tutup
                        </button>
                    </div>

                    <div class="space-y-5 px-5 py-4">
                        <section v-if="selectedProduct.variants.length > 0">
                            <h3 class="text-sm font-semibold text-ink">
                                Pilih ukuran
                            </h3>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button
                                    v-for="variant in selectedProduct.variants"
                                    :key="variant.id"
                                    type="button"
                                    class="flex min-h-12 items-center justify-between gap-2 rounded-xl border px-3 text-sm font-semibold transition"
                                    :class="
                                        draftVariantId === variant.id
                                            ? 'border-brand bg-brand/5 text-brand'
                                            : 'border-line bg-white text-ink'
                                    "
                                    :aria-pressed="
                                        draftVariantId === variant.id
                                    "
                                    @click="draftVariantId = variant.id"
                                >
                                    <span>{{ variant.name }}</span>
                                    <Money :value="variant.price" />
                                </button>
                            </div>
                        </section>

                        <section
                            v-for="group in selectedProduct.modifier_groups"
                            :key="group.id"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-semibold text-ink">
                                    {{ group.name }}
                                </h3>
                                <span class="text-xs text-muted">
                                    {{ groupHint(group) }}
                                </span>
                            </div>

                            <div class="mt-2 space-y-2">
                                <label
                                    v-for="modifier in group.modifiers"
                                    :key="modifier.id"
                                    class="flex min-h-12 items-center justify-between gap-3 rounded-xl border px-3 text-sm"
                                    :class="
                                        draftModifierIds.includes(modifier.id)
                                            ? 'border-brand bg-brand/5'
                                            : 'border-line bg-white'
                                    "
                                >
                                    <span class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                                            :checked="
                                                draftModifierIds.includes(
                                                    modifier.id,
                                                )
                                            "
                                            :disabled="
                                                isModifierDisabled(
                                                    group,
                                                    modifier,
                                                )
                                            "
                                            @change="
                                                toggleModifier(group, modifier)
                                            "
                                        />
                                        <span class="font-medium text-ink">
                                            {{ modifier.name }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="modifier.price_delta !== 0"
                                        class="text-xs font-semibold text-muted"
                                    >
                                        {{
                                            formatSignedRupiah(
                                                modifier.price_delta,
                                            )
                                        }}
                                    </span>
                                </label>

                                <p
                                    v-if="group.modifiers.length === 0"
                                    class="text-sm text-danger"
                                >
                                    Belum ada pilihan modifier aktif.
                                </p>
                            </div>
                        </section>

                        <label class="block">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Catatan item (opsional)
                            </span>
                            <textarea
                                v-model="draftNote"
                                rows="2"
                                class="w-full rounded-xl border border-line bg-white px-3.5 py-3 text-base text-ink outline-none placeholder:text-muted/70 focus:border-brand focus:ring-2 focus:ring-brand/20"
                                placeholder="Contoh: tanpa bawang"
                            />
                        </label>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-ink">
                                Jumlah
                            </span>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="h-11 w-11 rounded-xl border border-line text-lg font-semibold text-ink disabled:opacity-40"
                                    :disabled="draftQuantity <= 1"
                                    aria-label="Kurangi jumlah"
                                    @click="
                                        draftQuantity = Math.max(
                                            1,
                                            draftQuantity - 1,
                                        )
                                    "
                                >
                                    −
                                </button>
                                <span
                                    class="w-10 text-center text-base font-semibold"
                                >
                                    {{ draftQuantity }}
                                </span>
                                <button
                                    type="button"
                                    class="h-11 w-11 rounded-xl border border-line text-lg font-semibold text-ink disabled:opacity-40"
                                    :disabled="draftQuantity >= 99"
                                    aria-label="Tambah jumlah"
                                    @click="
                                        draftQuantity = Math.min(
                                            99,
                                            draftQuantity + 1,
                                        )
                                    "
                                >
                                    +
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="draftError"
                            class="rounded-xl border border-danger/20 bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ draftError }}
                        </div>
                    </div>

                    <div
                        class="sticky bottom-0 border-t border-line bg-white px-5 py-4"
                    >
                        <Button class="w-full" @click="addDraftToCart">
                            Tambah ·
                            <Money :value="draftUnitPrice * draftQuantity" />
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <PaymentSheet
            :open="paymentOpen"
            :order="createdOrder"
            @close="closePayment"
            @paid="onPaid"
        />

        <ReceiptPreview
            :open="receiptOpen"
            :order="paidOrder"
            allow-new-order
            @close="receiptOpen = false"
            @new-order="startNewOrder"
        />
    </StaffLayout>
</template>
