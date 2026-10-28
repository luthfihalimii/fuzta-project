<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/Button.vue';

const props = defineProps<{ shiftId: number }>();

const form = useForm({
    direction: 'in' as 'in' | 'out',
    amount: '',
    reason: '',
    idempotency_key: crypto.randomUUID(),
});

const shiftError = computed(
    () => (form.errors as Record<string, string>).shift,
);

function submit(): void {
    form.post(`/admin/shifts/${props.shiftId}/cash-movements`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'reason');
            form.idempotency_key = crypto.randomUUID();
        },
    });
}
</script>

<template>
    <section class="rounded-2xl border border-line bg-white p-5">
        <h2 class="font-semibold text-ink">Catat kas masuk/keluar</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink"
                    >Arah</span
                >
                <select
                    v-model="form.direction"
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink"
                >
                    <option value="in">Masuk</option>
                    <option value="out">Keluar</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink"
                    >Jumlah</span
                >
                <input
                    v-model="form.amount"
                    type="number"
                    min="1"
                    step="1"
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-right text-sm text-ink"
                />
            </label>
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink"
                    >Alasan</span
                >
                <input
                    v-model="form.reason"
                    type="text"
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-sm text-ink"
                    placeholder="Kas kecil, tambahan kas, koreksi"
                />
            </label>
        </div>
        <p v-if="form.errors.amount" class="mt-2 text-sm text-danger">
            {{ form.errors.amount }}
        </p>
        <p v-if="form.errors.reason" class="mt-2 text-sm text-danger">
            {{ form.errors.reason }}
        </p>
        <p v-if="shiftError" class="mt-2 text-sm text-danger">
            {{ shiftError }}
        </p>
        <Button class="mt-3" :disabled="form.processing" @click="submit">
            {{ form.processing ? 'Menyimpan…' : 'Simpan kas' }}
        </Button>
    </section>
</template>
