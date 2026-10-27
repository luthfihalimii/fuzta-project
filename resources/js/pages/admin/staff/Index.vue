<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Select from '@/components/Select.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type StaffRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    branch_id: number | null;
    branch_name: string | null;
    is_active: boolean;
};

type BranchOption = {
    id: number;
    name: string;
    code: string;
};

type RoleOption = {
    value: string;
    label: string;
};

const props = defineProps<{
    staff: StaffRow[];
    branches: BranchOption[];
    roles: RoleOption[];
}>();

const page = usePage();
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'cashier',
    branch_id: null as number | null,
    is_active: true,
    reason: '',
});

const branchOptions = computed(() =>
    props.branches.map((branch) => ({
        value: branch.id,
        label: `${branch.code} — ${branch.name}`,
    })),
);

const roleOptions = computed(() =>
    props.roles.map((role) => ({
        value: role.value,
        label: role.label,
    })),
);

const isEditing = computed(() => editingId.value !== null);

const formTitle = computed(() =>
    isEditing.value ? 'Ubah staf' : 'Tambah staf',
);

function startEdit(row: StaffRow): void {
    editingId.value = row.id;
    form.name = row.name;
    form.email = row.email;
    form.password = '';
    form.role = row.role;
    form.branch_id = row.branch_id;
    form.is_active = row.is_active;
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
        form.patch(`/admin/staff/${editingId.value}`, options);
        return;
    }

    form.post('/admin/staff', options);
}

function toggleActive(row: StaffRow): void {
    if (row.is_active) {
        const reason = window.prompt(`Alasan menonaktifkan ${row.name}?`);

        if (reason === null) {
            return;
        }

        router.patch(
            `/admin/staff/${row.id}`,
            { is_active: false, reason },
            { preserveScroll: true },
        );

        return;
    }

    router.patch(
        `/admin/staff/${row.id}`,
        { is_active: true },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Staf" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Staf</h1>
                    <p class="mt-1 text-sm text-muted">
                        Kelola akses pemilik, kasir, dan dapur.
                    </p>
                </div>
                <Badge tone="info">{{ props.staff.length }} akun</Badge>
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
                        v-model="form.email"
                        label="Email"
                        type="email"
                        autocomplete="off"
                        :error="form.errors.email"
                        required
                    />
                    <Input
                        v-model="form.password"
                        :label="
                            isEditing
                                ? 'Kata sandi baru (opsional)'
                                : 'Kata sandi'
                        "
                        type="password"
                        autocomplete="new-password"
                        :error="form.errors.password"
                        :required="!isEditing"
                    />
                    <Select
                        v-model="form.role"
                        label="Peran"
                        :options="roleOptions"
                        :error="form.errors.role"
                        required
                    />
                    <Select
                        v-model="form.branch_id"
                        label="Cabang"
                        :options="branchOptions"
                        placeholder="Tanpa cabang (khusus pemilik)"
                        :error="form.errors.branch_id"
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
                        Akun aktif
                    </label>

                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : isEditing
                                      ? 'Simpan perubahan'
                                      : 'Tambah staf'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-line bg-white">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-semibold text-ink">Daftar staf</h2>
                </div>

                <div v-if="props.staff.length === 0" class="p-5">
                    <EmptyState
                        title="Belum ada staf"
                        description="Tambahkan akun kasir atau dapur lewat formulir di atas."
                    />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="bg-canvas text-xs tracking-wide text-muted uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Nama</th>
                                <th class="px-5 py-3">Email</th>
                                <th class="px-5 py-3">Peran</th>
                                <th class="px-5 py-3">Cabang</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in props.staff" :key="row.id">
                                <td class="px-5 py-4 font-medium text-ink">
                                    {{ row.name }}
                                </td>
                                <td class="px-5 py-4 text-muted">
                                    {{ row.email }}
                                </td>
                                <td class="px-5 py-4">{{ row.role_label }}</td>
                                <td class="px-5 py-4 text-muted">
                                    {{ row.branch_name ?? 'Semua cabang' }}
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
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="secondary"
                                            @click="startEdit(row)"
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            :variant="
                                                row.is_active
                                                    ? 'danger'
                                                    : 'primary'
                                            "
                                            @click="toggleActive(row)"
                                        >
                                            {{
                                                row.is_active
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
