<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type CategoryRow = {
    id: number;
    name: string;
    sort_order: number;
    is_active: boolean;
    products_count: number;
};

const props = defineProps<{
    categories: CategoryRow[];
}>();

const page = usePage();
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    sort_order: 0,
    is_active: true,
});

const isEditing = computed(() => editingId.value !== null);

const formTitle = computed(() =>
    isEditing.value ? 'Ubah kategori' : 'Tambah kategori',
);

function startEdit(category: CategoryRow): void {
    editingId.value = category.id;
    form.name = category.name;
    form.sort_order = category.sort_order;
    form.is_active = category.is_active;
    form.clearErrors();
}

function cancelEdit(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            form.reset();
        },
    };

    if (editingId.value !== null) {
        form.patch(`/admin/catalog/categories/${editingId.value}`, options);

        return;
    }

    form.post('/admin/catalog/categories', options);
}
</script>

<template>
    <Head title="Kategori" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Kategori</h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelompokkan menu agar kasir mudah menemukan produk.
                    </p>
                </div>
                <Badge tone="info">{{ props.categories.length }} kategori</Badge>
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
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-ink">{{ formTitle }}</h2>
                    <button
                        v-if="isEditing"
                        type="button"
                        class="text-sm font-semibold text-muted underline"
                        @click="cancelEdit"
                    >
                        Batal ubah
                    </button>
                </div>

                <form
                    class="mt-4 grid gap-4 sm:grid-cols-[1fr_140px_auto] sm:items-end"
                    @submit.prevent="submit"
                >
                    <Input
                        v-model="form.name"
                        label="Nama kategori"
                        :error="form.errors.name"
                        required
                    />
                    <Input
                        v-model="form.sort_order"
                        type="number"
                        min="0"
                        label="Urutan"
                        :error="form.errors.sort_order"
                    />
                    <label
                        class="flex min-h-12 items-center gap-2 text-sm text-muted"
                    >
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                        />
                        Aktif
                    </label>

                    <div class="sm:col-span-3">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : isEditing
                                      ? 'Simpan perubahan'
                                      : 'Tambah kategori'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Daftar kategori</h2>
                </div>

                <div v-if="props.categories.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada kategori"
                        description="Tambahkan kategori pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Nama</th>
                                <th class="px-5 py-3">Produk</th>
                                <th class="px-5 py-3">Urutan</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="category in props.categories" :key="category.id">
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ category.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ category.products_count }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ category.sort_order }}
                                </td>
                                <td class="px-5 py-4">
                                    <Badge
                                        :tone="
                                            category.is_active
                                                ? 'success'
                                                : 'danger'
                                        "
                                    >
                                        {{
                                            category.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end">
                                        <Button
                                            variant="secondary"
                                            @click="startEdit(category)"
                                        >
                                            Ubah
                                        </Button>
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
