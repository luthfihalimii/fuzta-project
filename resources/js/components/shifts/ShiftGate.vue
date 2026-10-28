<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/Button.vue';
import Money from '@/components/Money.vue';
import type { BranchSummary } from '@/lib/order';
import type { ShiftWindow } from '@/lib/shift';

const props = defineProps<{
    branch: BranchSummary | null;
    window: ShiftWindow | null;
}>();

const form = useForm({
    branch_id: props.branch?.id ?? null,
    opening_cash_confirmed: false,
});

const shiftError = computed(
    () => (form.errors as Record<string, string>).shift,
);

function submit(): void {
    form.post('/pos/shift/open', { preserveScroll: true });
}
</script>

<template>
    <section class="rounded-2xl border border-line bg-white p-5">
        <h2 class="text-lg font-bold text-ink">Buka shift</h2>
        <p class="mt-1 text-sm text-muted">
            Buat pesanan dan terima pembayaran hanya setelah shift dibuka.
        </p>

        <div
            v-if="!props.branch"
            class="mt-4 rounded-xl bg-warning/10 p-4 text-sm text-warning"
        >
            Pilih cabang terlebih dahulu.
        </div>
        <div
            v-else-if="!props.branch.is_active"
            class="mt-4 rounded-xl bg-warning/10 p-4 text-sm text-warning"
        >
            Cabang ini nonaktif.
        </div>
        <div v-else class="mt-4 space-y-4">
            <div class="rounded-xl bg-canvas p-4">
                <p class="text-sm text-muted">
                    {{ props.branch.name }} · {{ props.branch.code }}
                </p>
                <p class="mt-1 text-sm text-muted">
                    {{
                        props.window
                            ? `${props.window.label} · ${props.window.start}–${props.window.end}`
                            : 'Di luar jam shift'
                    }}
                </p>
                <p class="mt-3 text-sm text-muted">Float kas awal</p>
                <p class="text-xl font-bold text-ink">
                    <Money :value="props.branch.cash_float" />
                </p>
            </div>

            <label class="flex items-start gap-3 text-sm text-ink">
                <input
                    v-model="form.opening_cash_confirmed"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-brand"
                />
                <span>
                    Saya sudah menghitung uang fisik dan jumlahnya sesuai float.
                </span>
            </label>
            <p
                v-if="form.errors.opening_cash_confirmed"
                class="text-sm text-danger"
            >
                {{ form.errors.opening_cash_confirmed }}
            </p>
            <p v-if="form.errors.branch_id" class="text-sm text-danger">
                {{ form.errors.branch_id }}
            </p>
            <p v-if="shiftError" class="text-sm text-danger">
                {{ shiftError }}
            </p>

            <Button
                class="w-full"
                :disabled="form.processing || !props.window"
                @click="submit"
            >
                {{ form.processing ? 'Membuka…' : 'Buka shift' }}
            </Button>
        </div>
    </section>
</template>
