<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { BranchOption, BranchSummary } from '@/lib/order';

type StockRow = {
    ingredient_id: number;
    name: string;
    unit: string | null;
    quantity: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    ingredients: StockRow[];
    low_stock: StockRow[];
}>();

const page = usePage();
const branchSelect = ref<number | null>(props.branch?.id ?? null);
const counted = ref<Record<number, string>>({});

const wasteForm = useForm({
    branch_id: props.branch?.id ?? null,
    ingredient_id: null as number | null,
    quantity: 1,
    reason: '',
});

const countForm = useForm({
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
        '/kitchen/stock',
        { branch: branchSelect.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

function submitWaste(): void {
    if (props.branch === null) {
        return;
    }

    wasteForm.branch_id = props.branch.id;
    wasteForm.post('/kitchen/stock/waste', {
        preserveScroll: true,
        onSuccess: () => {
            wasteForm.reset('ingredient_id', 'quantity', 'reason');
            wasteForm.quantity = 1;
        },
    });
}

function submitCount(): void {
    if (props.branch === null) {
        return;
    }

    if (enteredItems.value.length === 0) {
        countForm.setError('counted_items', 'Isi minimal satu bahan.');
        return;
    }

    countForm.branch_id = props.branch.id;
    countForm.counted_items = enteredItems.value;
    countForm.post('/kitchen/stock/counts', {
        preserveScroll: true,
        onSuccess: () => {
            counted.value = {};
            countForm.reset();
        },
    });
}

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <Head title="Stok Dapur" />

    <div class="min-h-screen bg-[#0d120e] text-[#f4f1e8]">
        <header
            class="sticky top-0 z-20 border-b border-white/10 bg-[#0d120e]/95 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Link
                        href="/"
                        class="shrink-0 text-lg font-black tracking-tight text-[#ffc72c]"
                    >
                        Pizza Fruzta
                    </Link>
                    <h1 class="text-sm font-semibold text-white/60">Stok Dapur</h1>
                    <span
                        v-if="props.branch"
                        class="truncate text-sm font-semibold text-white/80"
                    >
                        {{ props.branch.name }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="props.canChooseBranch && props.branches.length > 0">
                        <label for="stock-branch" class="sr-only">Cabang</label>
                        <select
                            id="stock-branch"
                            v-model="branchSelect"
                            class="min-h-11 rounded-xl border border-white/15 bg-white/5 px-3 text-sm font-semibold text-white outline-none focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                            @change="changeBranch"
                        >
                            <option
                                v-for="option in props.branches"
                                :key="option.id"
                                :value="option.id"
                                class="bg-[#1a201b]"
                            >
                                {{ option.name }}
                            </option>
                        </select>
                    </template>

                    <Link
                        href="/kitchen"
                        class="inline-flex min-h-11 items-center rounded-xl border border-white/15 px-3 text-sm font-semibold text-white/80 transition hover:bg-white/10"
                    >
                        Dapur
                    </Link>
                    <Link
                        v-if="page.props.auth.user?.role === 'owner'"
                        href="/admin"
                        class="hidden min-h-11 items-center rounded-xl border border-white/15 px-3 text-sm font-semibold text-white/80 transition hover:bg-white/10 sm:flex"
                    >
                        Admin
                    </Link>
                    <button
                        type="button"
                        class="min-h-11 rounded-xl px-3 text-sm font-semibold text-white/60 transition hover:bg-white/10 hover:text-white"
                        @click="logout"
                    >
                        Keluar
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1200px] px-4 py-5 sm:px-6">
            <div
                v-if="page.props.flash.success"
                class="mb-4 rounded-xl border border-[#22c55e]/40 bg-[#22c55e]/10 px-4 py-3 text-sm font-semibold text-[#86efac]"
                role="status"
            >
                {{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash.error"
                class="mb-4 rounded-xl border border-[#ef4444]/40 bg-[#ef4444]/10 px-4 py-3 text-sm font-semibold text-[#fca5a5]"
                role="alert"
            >
                {{ page.props.flash.error }}
            </div>

            <div
                v-if="!props.branch"
                class="rounded-2xl border border-white/10 bg-white/5 px-6 py-16 text-center"
            >
                <p class="text-xl font-bold">Cabang belum tersedia.</p>
                <p class="mt-2 text-sm text-white/60">
                    Pilih cabang aktif terlebih dahulu.
                </p>
            </div>

            <template v-else>
                <section class="mb-5">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h2 class="text-lg font-bold">Stok menipis</h2>
                            <p class="mt-1 text-sm text-white/60">
                                Bahan pada atau di bawah ambang minimum.
                            </p>
                        </div>
                        <span
                            class="rounded-full border border-[#ffc72c]/40 bg-[#ffc72c]/10 px-3 py-1 text-xs font-bold text-[#ffc72c]"
                        >
                            {{ props.low_stock.length }} bahan
                        </span>
                    </div>

                    <div
                        v-if="props.low_stock.length === 0"
                        class="mt-3 rounded-2xl border border-dashed border-white/15 bg-white/[0.03] px-5 py-6 text-sm text-white/60"
                    >
                        Semua stok di atas ambang minimum.
                    </div>

                    <div v-else class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="ingredient in props.low_stock"
                            :key="ingredient.ingredient_id"
                            class="rounded-2xl border border-[#ffc72c]/30 bg-[#ffc72c]/10 p-4"
                        >
                            <p class="font-bold text-white">{{ ingredient.name }}</p>
                            <p class="mt-1 text-sm text-white/70">
                                <span class="text-xl font-black tabular-nums text-[#ffc72c]">
                                    {{ ingredient.quantity }}
                                </span>
                                {{ ingredient.unit }} / min.
                                {{ ingredient.low_stock_threshold }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="grid gap-5 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
                    <section class="rounded-2xl border border-white/10 bg-[#161d18] p-4">
                        <h2 class="text-lg font-bold">Catat stok rusak</h2>
                        <p class="mt-1 text-sm text-white/60">
                            Jumlah akan mengurangi stok cabang ini.
                        </p>

                        <form class="mt-4 space-y-4" @submit.prevent="submitWaste">
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-white/80">
                                    Bahan
                                </span>
                                <select
                                    v-model="wasteForm.ingredient_id"
                                    required
                                    class="min-h-12 w-full rounded-xl border border-white/15 bg-white/5 px-3 text-base text-white outline-none focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                                >
                                    <option :value="null" class="bg-[#1a201b]">
                                        Pilih bahan
                                    </option>
                                    <option
                                        v-for="ingredient in props.ingredients"
                                        :key="ingredient.ingredient_id"
                                        :value="ingredient.ingredient_id"
                                        class="bg-[#1a201b]"
                                    >
                                        {{ ingredient.name }} — {{ ingredient.quantity }}
                                        {{ ingredient.unit }}
                                    </option>
                                </select>
                                <span
                                    v-if="wasteForm.errors.ingredient_id"
                                    class="mt-1.5 block text-sm text-[#fca5a5]"
                                >
                                    {{ wasteForm.errors.ingredient_id }}
                                </span>
                            </label>

                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-white/80">
                                    Jumlah
                                </span>
                                <input
                                    v-model="wasteForm.quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    inputmode="numeric"
                                    required
                                    class="min-h-12 w-full rounded-xl border border-white/15 bg-white/5 px-3 text-base text-white outline-none focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                                />
                                <span
                                    v-if="wasteForm.errors.quantity"
                                    class="mt-1.5 block text-sm text-[#fca5a5]"
                                >
                                    {{ wasteForm.errors.quantity }}
                                </span>
                            </label>

                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-white/80">
                                    Alasan
                                </span>
                                <input
                                    v-model="wasteForm.reason"
                                    type="text"
                                    required
                                    class="min-h-12 w-full rounded-xl border border-white/15 bg-white/5 px-3 text-base text-white outline-none placeholder:text-white/35 focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                                    placeholder="Contoh: rusak saat persiapan"
                                />
                                <span
                                    v-if="wasteForm.errors.reason"
                                    class="mt-1.5 block text-sm text-[#fca5a5]"
                                >
                                    {{ wasteForm.errors.reason }}
                                </span>
                            </label>

                            <button
                                type="submit"
                                :disabled="wasteForm.processing || props.ingredients.length === 0"
                                class="min-h-12 w-full rounded-xl bg-[#f97316] px-4 text-base font-black text-[#1a1a1a] transition hover:bg-[#fb923c] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f97316] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{
                                    wasteForm.processing
                                        ? 'Menyimpan...'
                                        : 'Simpan stok rusak'
                                }}
                            </button>
                        </form>
                    </section>

                    <section class="rounded-2xl border border-white/10 bg-[#161d18] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 class="text-lg font-bold">Opname tutup</h2>
                                <p class="mt-1 text-sm text-white/60">
                                    Kosongkan bahan yang tidak dihitung.
                                </p>
                            </div>
                            <span class="text-xs font-semibold text-white/55">
                                {{ enteredItems.length }} diisi
                            </span>
                        </div>

                        <div class="mt-4">
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-white/80">
                                    Catatan (opsional)
                                </span>
                                <input
                                    v-model="countForm.note"
                                    type="text"
                                    class="min-h-12 w-full rounded-xl border border-white/15 bg-white/5 px-3 text-base text-white outline-none placeholder:text-white/35 focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                                    placeholder="Contoh: opname tutup shift"
                                />
                            </label>
                        </div>

                        <div
                            v-if="props.ingredients.length === 0"
                            class="mt-4 rounded-xl border border-dashed border-white/15 bg-white/[0.03] px-4 py-8 text-center text-sm text-white/60"
                        >
                            Belum ada bahan untuk dihitung.
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <label
                                v-for="ingredient in props.ingredients"
                                :key="ingredient.ingredient_id"
                                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-3"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-white">
                                        {{ ingredient.name }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-white/55">
                                        Sistem: {{ ingredient.quantity }}
                                        {{ ingredient.unit }}
                                        <span
                                            v-if="ingredient.is_low_stock"
                                            class="ml-1 font-bold text-[#ffc72c]"
                                        >
                                            · Menipis
                                        </span>
                                    </span>
                                </span>
                                <input
                                    v-model="counted[ingredient.ingredient_id]"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    :aria-label="`Hitung ${ingredient.name}`"
                                    class="min-h-12 w-24 rounded-xl border border-white/15 bg-white/5 px-3 text-right text-base font-bold text-white outline-none focus:border-[#ffc72c] focus:ring-2 focus:ring-[#ffc72c]/30"
                                    placeholder="—"
                                />
                            </label>
                        </div>

                        <p
                            v-if="countForm.errors.counted_items"
                            class="mt-3 text-sm font-semibold text-[#fca5a5]"
                        >
                            {{ countForm.errors.counted_items }}
                        </p>

                        <button
                            type="button"
                            :disabled="countForm.processing || props.ingredients.length === 0"
                            class="mt-4 min-h-12 w-full rounded-xl bg-[#ffc72c] px-4 text-base font-black text-[#1a1a1a] transition hover:bg-[#ffd95c] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#ffc72c] disabled:cursor-not-allowed disabled:opacity-50"
                            @click="submitCount"
                        >
                            {{
                                countForm.processing
                                    ? 'Menyimpan...'
                                    : 'Simpan opname'
                            }}
                        </button>
                    </section>
                </div>
            </template>
        </main>
    </div>
</template>
