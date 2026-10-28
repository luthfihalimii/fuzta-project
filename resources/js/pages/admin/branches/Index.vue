<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import { formatRupiah } from '@/lib/money';
import StaffLayout from '@/layouts/StaffLayout.vue';

type BranchRow = {
    id: number;
    name: string;
    code: string;
    address: string | null;
    phone: string | null;
    cash_float: number;
    is_active: boolean;
    users_count: number;
};

const props = defineProps<{
    branches: BranchRow[];
}>();

const page = usePage();
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    code: '',
    address: '',
    phone: '',
    cash_float: '0',
    is_active: true,
    reason: '',
});

const isEditing = computed(() => editingId.value !== null);

const formTitle = computed(() =>
    isEditing.value ? 'Ubah cabang' : 'Tambah cabang',
);

function startEdit(branch: BranchRow): void {
    editingId.value = branch.id;
    form.name = branch.name;
    form.code = branch.code;
    form.address = branch.address ?? '';
    form.phone = branch.phone ?? '';
    form.cash_float = String(branch.cash_float);
    form.is_active = branch.is_active;
    form.reason = '';
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
        form.patch(`/admin/branches/${editingId.value}`, options);
        return;
    }

    form.post('/admin/branches', options);
}

function toggleActive(branch: BranchRow): void {
    if (branch.is_active) {
        const reason = window.prompt(`Alasan menonaktifkan ${branch.name}?`);

        if (reason === null) {
            return;
        }

        router.patch(
            `/admin/branches/${branch.id}`,
            { is_active: false, reason },
            { preserveScroll: true },
        );

        return;
    }

    router.patch(
        `/admin/branches/${branch.id}`,
        { is_active: true },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Cabang" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Cabang</h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelola cabang yang beroperasi.
                    </p>
                </div>
                <Badge tone="info">{{ props.branches.length }} cabang</Badge>
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
                        label="Nama cabang"
                        :error="form.errors.name"
                        required
                    />
                    <Input
                        v-model="form.code"
                        label="Kode cabang"
                        placeholder="PF-01"
                        :error="form.errors.code"
                        required
                    />
                    <Input
                        v-model="form.address"
                        label="Alamat"
                        :error="form.errors.address"
                    />
                    <Input
                        v-model="form.phone"
                        label="Telepon"
                        :error="form.errors.phone"
                    />
                    <Input
                        v-model="form.cash_float"
                        label="Float kas"
                        type="number"
                        min="0"
                        hint="Uang fisik yang harus ada di awal shift."
                        :error="form.errors.cash_float"
                    />
                    <Input
                        v-if="isEditing"
                        v-model="form.reason"
                        label="Alasan perubahan (opsional)"
                        :error="form.errors.reason"
                    />

                    <label
                        class="flex min-h-12 items-center gap-2 text-sm text-muted"
                    >
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                        />
                        Cabang aktif
                    </label>

                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : isEditing
                                      ? 'Simpan perubahan'
                                      : 'Tambah cabang'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Daftar cabang</h2>
                </div>

                <div v-if="props.branches.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada cabang"
                        description="Tambahkan cabang pertama lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Kode</th>
                                <th class="px-5 py-3">Nama</th>
                                <th class="px-5 py-3">Alamat</th>
                                <th class="px-5 py-3">Telepon</th>
                                <th class="px-5 py-3">Float kas</th>
                                <th class="px-5 py-3">Staf</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="branch in props.branches"
                                :key="branch.id"
                            >
                                <td
                                    class="px-5 py-4 font-mono font-medium text-ink"
                                >
                                    {{ branch.code }}
                                </td>
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ branch.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ branch.address ?? '—' }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ branch.phone ?? '—' }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ formatRupiah(branch.cash_float) }}
                                </td>
                                <td class="px-5 py-4">
                                    {{ branch.users_count }}
                                </td>
                                <td class="px-5 py-4">
                                    <Badge
                                        :tone="
                                            branch.is_active
                                                ? 'success'
                                                : 'danger'
                                        "
                                    >
                                        {{
                                            branch.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="secondary"
                                            @click="startEdit(branch)"
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            :variant="
                                                branch.is_active
                                                    ? 'danger'
                                                    : 'primary'
                                            "
                                            @click="toggleActive(branch)"
                                        >
                                            {{
                                                branch.is_active
                                                    ? 'Nonaktifkan'
                                                    : 'Aktifkan'
                                            }}
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
