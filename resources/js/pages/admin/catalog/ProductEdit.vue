<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Button.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type VariantDraft = {
    id: number | null;
    name: string;
    price: number;
    is_active: boolean;
};

type BranchAvailability = {
    branch_id: number;
    is_available: boolean;
};

type ProductData = {
    id: number;
    category_id: number;
    name: string;
    description: string | null;
    base_price: number;
    is_active: boolean;
    image_url: string | null;
    variants: Array<{
        id: number;
        name: string;
        price: number;
        is_active: boolean;
    }>;
    modifier_group_ids: number[];
    branch_availability: BranchAvailability[];
};

type CategoryOption = {
    id: number;
    name: string;
};

type ModifierGroupOption = {
    id: number;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
};

type BranchOption = {
    id: number;
    code: string;
    name: string;
};

const props = defineProps<{
    product: ProductData | null;
    categories: CategoryOption[];
    modifierGroups: ModifierGroupOption[];
    branches: BranchOption[];
}>();

const pageTitle = computed(() =>
    props.product ? 'Ubah produk' : 'Tambah produk',
);

const initialVariants: VariantDraft[] =
    props.product?.variants.map((variant) => ({
        id: variant.id,
        name: variant.name,
        price: variant.price,
        is_active: variant.is_active,
    })) ?? [];

const availabilityByBranch = new Map(
    (props.product?.branch_availability ?? []).map((row) => [
        row.branch_id,
        row.is_available,
    ]),
);

const initialBranchAvailability: BranchAvailability[] = props.branches.map(
    (branch) => ({
        branch_id: branch.id,
        is_available: props.product
            ? (availabilityByBranch.get(branch.id) ?? false)
            : true,
    }),
);

const form = useForm({
    category_id: props.product?.category_id ?? null,
    name: props.product?.name ?? '',
    description: props.product?.description ?? '',
    base_price: props.product?.base_price ?? 0,
    is_active: props.product?.is_active ?? true,
    variants: initialVariants,
    modifier_group_ids: props.product?.modifier_group_ids ?? [],
    branch_availability: initialBranchAvailability,
    image: null as File | null,
    remove_image: false,
});

const previewUrl = ref<string | null>(null);

function pickImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    form.image = file ?? null;

    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    previewUrl.value = file ? URL.createObjectURL(file) : null;

    if (file) {
        form.remove_image = false;
    }
}

// ponytail: re-pick file sama setelah hapus tak fire change; upgrade: template ref + input.value = '' bila owner komplain.
function hideImage(): void {
    form.remove_image = true;
    form.image = null;
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = null;
}

const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.id,
        label: category.name,
    })),
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function errorFor(key: string): string | undefined {
    return errors.value[key];
}

function addVariant(): void {
    form.variants.push({
        id: null,
        name: '',
        price: 0,
        is_active: true,
    });
}

function removeVariant(index: number): void {
    form.variants.splice(index, 1);
}

function submit(): void {
    if (props.product) {
        form.patch(`/admin/catalog/products/${props.product.id}`, {
            preserveScroll: true,
        });

        return;
    }

    form.post('/admin/catalog/products', { preserveScroll: true });
}
</script>

<template>
    <Head :title="pageTitle" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">{{ pageTitle }}</h1>
                    <p class="mt-1 text-sm text-muted">
                        Harga dihitung server saat checkout; data di sini hanya
                        sumber harga menu.
                    </p>
                </div>
                <Link
                    href="/admin/catalog/products"
                    class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-5 text-sm font-semibold text-ink transition hover:bg-canvas"
                >
                    Kembali
                </Link>
            </div>

            <div
                v-if="Object.keys(form.errors).length > 0"
                class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
            >
                <p class="font-semibold">
                    Produk belum bisa disimpan. Periksa data berikut:
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li v-for="(message, key) in form.errors" :key="key">
                        {{ message }}
                    </li>
                </ul>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <section class="rounded-2xl border border-line bg-white p-5">
                    <h2 class="font-semibold text-ink">Informasi produk</h2>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <Select
                                v-model="form.category_id"
                                label="Kategori"
                                :options="categoryOptions"
                                placeholder="Pilih kategori"
                                :error="form.errors.category_id"
                                required
                            />
                            <p
                                v-if="props.categories.length === 0"
                                class="mt-1 text-sm text-warning"
                            >
                                Belum ada kategori.
                                <Link
                                    href="/admin/catalog/categories"
                                    class="font-semibold underline"
                                >
                                    Tambah kategori
                                </Link>
                            </p>
                        </div>

                        <Input
                            v-model="form.name"
                            label="Nama produk"
                            :error="form.errors.name"
                            required
                        />

                        <div>
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Foto produk (opsional)
                            </span>
                            <div class="flex items-start gap-4">
                                <span
                                    class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-canvas text-xs text-muted"
                                >
                                    <img
                                        v-if="previewUrl"
                                        :src="previewUrl"
                                        alt="Pratinjau foto"
                                        class="h-full w-full object-cover"
                                    />
                                    <img
                                        v-else-if="
                                            !form.remove_image &&
                                            props.product?.image_url
                                        "
                                        :src="props.product.image_url"
                                        alt="Foto produk"
                                        class="h-full w-full object-cover"
                                    />
                                    <span v-else>
                                        {{
                                            props.product?.name
                                                ? props.product.name
                                                      .charAt(0)
                                                      .toUpperCase()
                                                : '—'
                                        }}
                                    </span>
                                </span>
                                <div class="space-y-2">
                                    <label
                                        class="inline-block cursor-pointer rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-ink hover:border-brand/40"
                                    >
                                        {{
                                            props.product?.image_url &&
                                            !form.remove_image &&
                                            !previewUrl
                                                ? 'Ganti foto'
                                                : 'Pilih foto'
                                        }}
                                        <input
                                            type="file"
                                            accept=".jpg,.jpeg,.png,.webp"
                                            class="hidden"
                                            @change="pickImage"
                                        />
                                    </label>
                                    <p class="text-xs text-muted">
                                        JPG, PNG, atau WebP; maksimal 5 MB.
                                    </p>
                                    <Button
                                        v-if="props.product?.image_url"
                                        type="button"
                                        variant="danger"
                                        @click="hideImage"
                                    >
                                        Hapus foto
                                    </Button>
                                    <p
                                        v-if="form.errors.image"
                                        class="text-sm text-danger"
                                    >
                                        {{ form.errors.image }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <label class="block sm:col-span-2">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Deskripsi (opsional)
                            </span>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-xl border border-line bg-white px-3.5 py-3 text-base text-ink transition outline-none placeholder:text-muted/70 focus:border-brand focus:ring-2 focus:ring-brand/20"
                            />
                            <span
                                v-if="form.errors.description"
                                class="mt-1.5 block text-sm text-danger"
                            >
                                {{ form.errors.description }}
                            </span>
                        </label>

                        <label class="block">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Harga dasar (IDR)
                            </span>
                            <input
                                v-model.number="form.base_price"
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                            />
                            <span
                                v-if="errorFor('base_price')"
                                class="mt-1.5 block text-sm text-danger"
                            >
                                {{ errorFor('base_price') }}
                            </span>
                            <span class="mt-1.5 block text-sm text-muted">
                                Dipakai jika produk tidak punya varian.
                            </span>
                        </label>

                        <label
                            class="flex min-h-12 items-center gap-2 self-end text-sm text-muted"
                        >
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                            />
                            Produk aktif
                        </label>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white p-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <h2 class="font-semibold text-ink">Varian</h2>
                            <p class="mt-1 text-sm text-muted">
                                Kosongkan jika produk punya satu harga.
                            </p>
                        </div>
                        <Button variant="secondary" @click="addVariant">
                            Tambah varian
                        </Button>
                    </div>

                    <div
                        v-if="form.variants.length === 0"
                        class="mt-4 rounded-xl border border-dashed border-line p-4 text-sm text-muted"
                    >
                        Belum ada varian. Produk memakai harga dasar.
                    </div>

                    <div v-else class="mt-4 space-y-3">
                        <div
                            v-for="(variant, index) in form.variants"
                            :key="variant.id ?? `baru-${index}`"
                            class="grid gap-3 rounded-xl border border-line p-4 sm:grid-cols-[1fr_160px_auto_auto] sm:items-end"
                        >
                            <Input
                                v-model="variant.name"
                                label="Nama varian"
                                :error="errorFor(`variants.${index}.name`)"
                            />

                            <label class="block">
                                <span
                                    class="mb-1.5 block text-sm font-medium text-ink"
                                >
                                    Harga (IDR)
                                </span>
                                <input
                                    v-model.number="variant.price"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                                />
                                <span
                                    v-if="errorFor(`variants.${index}.price`)"
                                    class="mt-1.5 block text-sm text-danger"
                                >
                                    {{ errorFor(`variants.${index}.price`) }}
                                </span>
                            </label>

                            <label
                                class="flex min-h-12 items-center gap-2 text-sm text-muted"
                            >
                                <input
                                    v-model="variant.is_active"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                                />
                                Aktif
                            </label>

                            <button
                                type="button"
                                class="min-h-12 rounded-xl px-4 text-sm font-semibold text-danger transition hover:bg-danger/10"
                                @click="removeVariant(index)"
                            >
                                Hapus
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white p-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <h2 class="font-semibold text-ink">Modifier</h2>
                            <p class="mt-1 text-sm text-muted">
                                Pilih grup topping atau pilihan tambahan.
                            </p>
                        </div>
                        <Link
                            href="/admin/catalog/modifier-groups"
                            class="text-sm font-semibold text-brand underline"
                        >
                            Kelola modifier
                        </Link>
                    </div>

                    <div
                        v-if="props.modifierGroups.length === 0"
                        class="mt-4 rounded-xl border border-dashed border-line p-4 text-sm text-muted"
                    >
                        Belum ada grup modifier.
                    </div>

                    <div v-else class="mt-4 grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="group in props.modifierGroups"
                            :key="group.id"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-line px-4 py-2 text-sm"
                        >
                            <input
                                v-model="form.modifier_group_ids"
                                type="checkbox"
                                :value="group.id"
                                class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                            />
                            <span class="font-medium text-ink">
                                {{ group.name }}
                            </span>
                            <span class="text-muted">
                                {{ group.min_select }}–{{ group.max_select }}
                                pilihan
                            </span>
                            <span
                                v-if="group.is_required"
                                class="ml-auto text-xs font-semibold text-warning"
                            >
                                Wajib
                            </span>
                        </label>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white p-5">
                    <h2 class="font-semibold text-ink">Ketersediaan cabang</h2>
                    <p class="mt-1 text-sm text-muted">
                        Centang cabang yang menjual produk ini. Cabang yang
                        tidak dicentang dianggap tidak tersedia.
                    </p>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="(branch, index) in props.branches"
                            :key="branch.id"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-line px-4 py-2 text-sm"
                        >
                            <input
                                v-model="
                                    form.branch_availability[index].is_available
                                "
                                type="checkbox"
                                class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                            />
                            <span class="font-mono text-xs text-muted">
                                {{ branch.code }}
                            </span>
                            <span class="font-medium text-ink">
                                {{ branch.name }}
                            </span>
                        </label>
                    </div>
                </section>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? 'Menyimpan...'
                                : props.product
                                  ? 'Simpan perubahan'
                                  : 'Tambah produk'
                        }}
                    </Button>
                    <Link
                        href="/admin/catalog/products"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-5 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Batal
                    </Link>
                </div>
            </form>
        </div>
    </StaffLayout>
</template>
