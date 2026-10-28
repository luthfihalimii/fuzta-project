<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/components/Button.vue';
import Money from '@/components/Money.vue';
import { suggestedDeposit, type ShiftSummaryData } from '@/lib/shift';

const props = defineProps<{ shift: ShiftSummaryData; endpoint?: string }>();

const form = useForm({
    counted_cash: String(props.shift.expected_cash ?? 0),
    cash_deposited: String(
        suggestedDeposit(
            props.shift.expected_cash ?? 0,
            props.shift.opening_cash,
        ),
    ),
    reconciliation_note: '',
});

const shiftError = computed(
    () => (form.errors as Record<string, string>).shift,
);

const counted = computed(() => {
    const value = Number(form.counted_cash);
    return Number.isFinite(value) && value >= 0 ? value : 0;
});

const variance = computed(
    () => counted.value - (props.shift.expected_cash ?? 0),
);

const deposit = computed(() => {
    const value = Number(form.cash_deposited);
    return Number.isFinite(value) && value >= 0 ? value : 0;
});

function submit(): void {
    form.post(props.endpoint ?? `/pos/shifts/${props.shift.id}/reconcile`, {
        preserveScroll: true,
        onSuccess: () => router.reload(),
    });
}
</script>

<template>
    <section class="rounded-2xl border border-warning/30 bg-warning/5 p-5">
        <h2 class="text-lg font-bold text-ink">Rekonsiliasi shift</h2>
        <p class="mt-1 text-sm text-muted">
            Shift ditutup otomatis. Masukkan kas fisik untuk menyelesaikan
            rekonsiliasi.
        </p>
        <p class="mt-2 text-sm text-muted">
            Kas seharusnya:
            <span class="font-semibold text-ink"
                ><Money :value="shift.expected_cash ?? 0"
            /></span>
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink"
                    >Kas dihitung</span
                >
                <input
                    v-model="form.counted_cash"
                    type="number"
                    min="0"
                    step="1"
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-right text-lg font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
            </label>
            <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink"
                    >Setoran</span
                >
                <input
                    v-model="form.cash_deposited"
                    type="number"
                    min="0"
                    step="1"
                    class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-right text-lg font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <span class="mt-1 block text-xs text-muted">
                    Saran:
                    <Money
                        :value="suggestedDeposit(counted, shift.opening_cash)"
                    />
                </span>
            </label>
            <label class="block sm:col-span-2">
                <span class="mb-1.5 block text-sm font-medium text-ink">
                    Catatan {{ variance !== 0 ? '(wajib)' : '(opsional)' }}
                </span>
                <textarea
                    v-model="form.reconciliation_note"
                    rows="2"
                    class="w-full rounded-xl border border-line bg-white px-3.5 py-3 text-base text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    placeholder="Contoh: kurang Rp5.000"
                />
            </label>
        </div>

        <div class="mt-3 flex items-center justify-between text-sm">
            <span class="text-muted">Selisih</span>
            <span
                class="font-semibold"
                :class="variance === 0 ? 'text-success' : 'text-danger'"
            >
                <Money :value="variance" />
            </span>
        </div>
        <p class="mt-1 text-xs text-muted">
            Float dipertahankan: <Money :value="counted - deposit" />
        </p>
        <p v-if="form.errors.counted_cash" class="mt-1 text-sm text-danger">
            {{ form.errors.counted_cash }}
        </p>
        <p v-if="form.errors.cash_deposited" class="mt-1 text-sm text-danger">
            {{ form.errors.cash_deposited }}
        </p>
        <p
            v-if="form.errors.reconciliation_note"
            class="mt-1 text-sm text-danger"
        >
            {{ form.errors.reconciliation_note }}
        </p>
        <p v-if="shiftError" class="mt-1 text-sm text-danger">
            {{ shiftError }}
        </p>

        <Button class="mt-4 w-full" :disabled="form.processing" @click="submit">
            {{ form.processing ? 'Menyimpan…' : 'Rekonsiliasi' }}
        </Button>
    </section>
</template>
