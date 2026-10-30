<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type SupplierRow = {
    id: number;
    name: string;
    phone: string | null;
    is_active: boolean;
};

const props = defineProps<{
    suppliers: SupplierRow[];
}>();

const page = usePage();
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    phone: '',
    is_active: true,
});

const isEditing = computed(() => editingId.value !== null);

const formTitle = computed(() =>
    isEditing.value ? 'Ubah supplier' : 'Tambah supplier',
);

function startEdit(row: SupplierRow): void {
    editingId.value = row.id;
    form.name = row.name;
    form.phone = row.phone ?? '';
    form.is_active = row.is_active;
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
        form.patch(`/admin/suppliers/${editingId.value}`, options);
        return;
    }

    form.post('/admin/suppliers', options);
}
</script>

<template>
    <Head title="Supplier" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Supplier</h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelola pemasok bahan untuk penerimaan barang.
                    </p>
                </div>
                <Badge tone="info">{{ props.suppliers.length }} supplier</Badge>
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
                    class="mt-4 grid gap-4 sm:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <Input
                        v-model="form.name"
                        label="Nama"
                        :error="form.errors.name"
                        required
                    />
                    <Input
                        v-model="form.phone"
                        label="Telepon (opsional)"
                        autocomplete="off"
                        :error="form.errors.phone"
                    />

                    <label
                        class="flex min-h-12 items-center gap-2 text-sm text-muted"
                    >
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                        />
                        Supplier aktif
                    </label>

                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : isEditing
                                      ? 'Simpan perubahan'
                                      : 'Tambah supplier'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Daftar supplier</h2>
                </div>

                <div v-if="props.suppliers.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada supplier"
                        description="Tambahkan pemasok lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Nama</th>
                                <th class="px-5 py-3">Telepon</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in props.suppliers" :key="row.id">
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ row.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ row.phone ?? '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <Badge
                                        :tone="
                                            row.is_active ? 'success' : 'danger'
                                        "
                                    >
                                        {{
                                            row.is_active ? 'Aktif' : 'Nonaktif'
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <Button
                                        variant="secondary"
                                        @click="startEdit(row)"
                                    >
                                        Ubah
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
