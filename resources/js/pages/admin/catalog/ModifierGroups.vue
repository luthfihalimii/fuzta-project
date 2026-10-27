<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/components/Badge.vue';
import Button from '@/components/Button.vue';
import EmptyState from '@/components/EmptyState.vue';
import Input from '@/components/Input.vue';
import Money from '@/components/Money.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

type ModifierDraft = {
    id: number | null;
    name: string;
    price_delta: number;
    is_active: boolean;
};

type ModifierRow = {
    id: number;
    name: string;
    price_delta: number;
    sort_order: number;
    is_active: boolean;
};

type GroupRow = {
    id: number;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    sort_order: number;
    modifiers: ModifierRow[];
};

const props = defineProps<{
    groups: GroupRow[];
}>();

const page = usePage();
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    min_select: 0,
    max_select: 1,
    is_required: false,
    sort_order: 0,
    modifiers: [] as ModifierDraft[],
});

const isEditing = computed(() => editingId.value !== null);

const formTitle = computed(() =>
    isEditing.value ? 'Ubah grup modifier' : 'Tambah grup modifier',
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function errorFor(key: string): string | undefined {
    return errors.value[key];
}

function startEdit(group: GroupRow): void {
    editingId.value = group.id;
    form.name = group.name;
    form.min_select = group.min_select;
    form.max_select = group.max_select;
    form.is_required = group.is_required;
    form.sort_order = group.sort_order;
    form.modifiers = group.modifiers.map((modifier) => ({
        id: modifier.id,
        name: modifier.name,
        price_delta: modifier.price_delta,
        is_active: modifier.is_active,
    }));
    form.clearErrors();
}

function cancelEdit(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function addModifier(): void {
    form.modifiers.push({
        id: null,
        name: '',
        price_delta: 0,
        is_active: true,
    });
}

function removeModifier(index: number): void {
    form.modifiers.splice(index, 1);
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
        form.patch(
            `/admin/catalog/modifier-groups/${editingId.value}`,
            options,
        );

        return;
    }

    form.post('/admin/catalog/modifier-groups', options);
}
</script>

<template>
    <Head title="Modifier" />

    <StaffLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Modifier</h1>
                    <p class="mt-1 text-sm text-muted">
                        Atur topping, crust, dan pilihan tambahan berbayar.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge tone="info">{{ props.groups.length }} grup</Badge>
                    <Link
                        href="/admin/catalog/products"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-5 text-sm font-semibold text-ink transition hover:bg-canvas"
                    >
                        Produk
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

                <div
                    v-if="Object.keys(form.errors).length > 0"
                    class="mt-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
                >
                    <p class="font-semibold">
                        Grup modifier belum bisa disimpan.
                    </p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li v-for="(message, key) in form.errors" :key="key">
                            {{ message }}
                        </li>
                    </ul>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Input
                            v-model="form.name"
                            label="Nama grup"
                            :error="form.errors.name"
                            required
                        />

                        <label class="block">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Minimum pilihan
                            </span>
                            <input
                                v-model.number="form.min_select"
                                type="number"
                                min="0"
                                step="1"
                                class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                            />
                            <span
                                v-if="form.errors.min_select"
                                class="mt-1.5 block text-sm text-danger"
                            >
                                {{ form.errors.min_select }}
                            </span>
                        </label>

                        <label class="block">
                            <span
                                class="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Maksimum pilihan
                            </span>
                            <input
                                v-model.number="form.max_select"
                                type="number"
                                min="0"
                                step="1"
                                class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                            />
                            <span
                                v-if="form.errors.max_select"
                                class="mt-1.5 block text-sm text-danger"
                            >
                                {{ form.errors.max_select }}
                            </span>
                        </label>

                        <Input
                            v-model="form.sort_order"
                            type="number"
                            min="0"
                            label="Urutan"
                            :error="form.errors.sort_order"
                        />
                    </div>

                    <label
                        class="flex min-h-12 items-center gap-2 text-sm text-muted"
                    >
                        <input
                            v-model="form.is_required"
                            type="checkbox"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                        />
                        Wajib dipilih saat memesan
                    </label>

                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-4"
                    >
                        <div>
                            <h3 class="font-semibold text-ink">Pilihan</h3>
                            <p class="mt-1 text-sm text-muted">
                                Harga tambahan boleh negatif untuk potongan.
                            </p>
                        </div>
                        <Button variant="secondary" @click="addModifier">
                            Tambah pilihan
                        </Button>
                    </div>

                    <div
                        v-if="form.modifiers.length === 0"
                        class="rounded-xl border border-dashed border-line p-4 text-sm text-muted"
                    >
                        Belum ada pilihan di grup ini.
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="(modifier, index) in form.modifiers"
                            :key="modifier.id ?? `baru-${index}`"
                            class="grid gap-3 rounded-xl border border-line p-4 sm:grid-cols-[1fr_160px_auto_auto] sm:items-end"
                        >
                            <Input
                                v-model="modifier.name"
                                label="Nama pilihan"
                                :error="errorFor(`modifiers.${index}.name`)"
                            />

                            <label class="block">
                                <span
                                    class="mb-1.5 block text-sm font-medium text-ink"
                                >
                                    Tambahan (IDR)
                                </span>
                                <input
                                    v-model.number="modifier.price_delta"
                                    type="number"
                                    step="1"
                                    inputmode="numeric"
                                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                                />
                                <span
                                    v-if="
                                        errorFor(
                                            `modifiers.${index}.price_delta`,
                                        )
                                    "
                                    class="mt-1.5 block text-sm text-danger"
                                >
                                    {{
                                        errorFor(
                                            `modifiers.${index}.price_delta`,
                                        )
                                    }}
                                </span>
                            </label>

                            <label
                                class="flex min-h-12 items-center gap-2 text-sm text-muted"
                            >
                                <input
                                    v-model="modifier.is_active"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                                />
                                Aktif
                            </label>

                            <button
                                type="button"
                                class="min-h-12 rounded-xl px-4 text-sm font-semibold text-danger transition hover:bg-danger/10"
                                @click="removeModifier(index)"
                            >
                                Hapus
                            </button>
                        </div>
                    </div>

                    <div class="border-t border-line pt-4">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Menyimpan...'
                                    : isEditing
                                      ? 'Simpan perubahan'
                                      : 'Tambah grup'
                            }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="space-y-3">
                <h2 class="font-semibold text-ink">Daftar grup</h2>

                <div v-if="props.groups.length === 0" class="p-1">
                    <EmptyState
                        title="Belum ada grup modifier"
                        description="Tambahkan grup seperti crust atau topping."
                    />
                </div>

                <article
                    v-for="group in props.groups"
                    :key="group.id"
                    class="rounded-2xl border border-line bg-white p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-ink">
                                    {{ group.name }}
                                </h3>
                                <Badge
                                    :tone="
                                        group.is_required
                                            ? 'warning'
                                            : 'neutral'
                                    "
                                >
                                    {{
                                        group.is_required ? 'Wajib' : 'Opsional'
                                    }}
                                </Badge>
                            </div>
                            <p class="mt-1 text-sm text-muted">
                                Pilih {{ group.min_select }}–{{
                                    group.max_select
                                }}
                                · urutan {{ group.sort_order }}
                            </p>
                        </div>
                        <Button variant="secondary" @click="startEdit(group)">
                            Ubah
                        </Button>
                    </div>

                    <div
                        v-if="group.modifiers.length === 0"
                        class="mt-4 rounded-xl border border-dashed border-line p-4 text-sm text-muted"
                    >
                        Belum ada pilihan.
                    </div>

                    <ul v-else class="mt-4 divide-y divide-line">
                        <li
                            v-for="modifier in group.modifiers"
                            :key="modifier.id"
                            class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-ink">
                                    {{ modifier.name }}
                                </span>
                                <Badge
                                    :tone="
                                        modifier.is_active
                                            ? 'success'
                                            : 'danger'
                                    "
                                >
                                    {{
                                        modifier.is_active
                                            ? 'Aktif'
                                            : 'Nonaktif'
                                    }}
                                </Badge>
                            </div>
                            <span class="text-muted">
                                {{ modifier.price_delta >= 0 ? '+' : '' }}
                                <Money :value="modifier.price_delta" />
                            </span>
                        </li>
                    </ul>
                </article>
            </section>
        </div>
    </StaffLayout>
</template>
