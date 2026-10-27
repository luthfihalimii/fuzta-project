<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type BranchAvailability = {
    branch_id: number;
    is_available: boolean;
};

type ProductRow = {
    id: number;
    name: string;
    category_name: string | null;
    base_price: number;
    is_active: boolean;
    variants_count: number;
    branch_availability: BranchAvailability[];
};

type BranchOption = {
    id: number;
    code: string;
    name: string;
};

const props = defineProps<{
    products: ProductRow[];
    branches: BranchOption[];
}>();

const page = usePage();

function isAvailable(product: ProductRow, branchId: number): boolean {
    return product.branch_availability.some(
        (row) => row.branch_id === branchId && row.is_available,
    );
}

function toggleBranch(
    product: ProductRow,
    branchId: number,
    event: Event,
): void {
    const checked = (event.target as HTMLInputElement).checked;

    router.patch(
        `/admin/catalog/products/${product.id}`,
        {
            branch_availability: props.branches.map((branch) => ({
                branch_id: branch.id,
                is_available:
                    branch.id === branchId
                        ? checked
                        : isAvailable(product, branch.id),
            })),
        },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <Head title="Produk" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Produk</h1>
                    <p class="mt-1 text-sm text-muted">
                        Atur menu, harga, varian, dan ketersediaan cabang.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/catalog/categories"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-5 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Kategori
                    </Link>
                    <Link
                        href="/admin/catalog/modifier-groups"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-5 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Modifier
                    </Link>
                    <Link
                        href="/admin/catalog/products/create"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white transition hover:bg-brand/90"
                    >
                        Tambah produk
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
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4"
                >
                    <h2 class="font-semibold text-ink">Daftar produk</h2>
                    <Badge tone="info"
                        >{{ props.products.length }} produk</Badge
                    >
                </div>

                <div v-if="props.products.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada produk"
                        description="Tambahkan produk pertama untuk mulai berjualan."
                    >
                        <Link
                            href="/admin/catalog/products/create"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white"
                        >
                            Tambah produk
                        </Link>
                    </EmptyState>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Produk</th>
                                <th class="px-5 py-3">Kategori</th>
                                <th class="px-5 py-3">Harga dasar</th>
                                <th class="px-5 py-3">Varian</th>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="product in props.products"
                                :key="product.id"
                            >
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ product.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ product.category_name ?? '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <Money :value="product.base_price" />
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ product.variants_count }}
                                </td>
                                <td class="px-5 py-4">
                                    <div
                                        v-if="props.branches.length === 0"
                                        class="text-muted"
                                    >
                                        Belum ada cabang
                                    </div>
                                    <div v-else class="flex flex-wrap gap-3">
                                        <label
                                            v-for="branch in props.branches"
                                            :key="branch.id"
                                            class="inline-flex min-h-12 items-center gap-1.5 text-xs font-medium text-muted"
                                            :title="branch.name"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    isAvailable(
                                                        product,
                                                        branch.id,
                                                    )
                                                "
                                                class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                                                @change="
                                                    toggleBranch(
                                                        product,
                                                        branch.id,
                                                        $event,
                                                    )
                                                "
                                            />
                                            {{ branch.code }}
                                        </label>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <Badge
                                        :tone="
                                            product.is_active
                                                ? 'success'
                                                : 'danger'
                                        "
                                    >
                                        {{
                                            product.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end">
                                        <Link
                                            :href="`/admin/catalog/products/${product.id}/edit`"
                                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                                        >
                                            Ubah
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
