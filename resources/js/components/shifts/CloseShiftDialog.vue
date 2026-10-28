<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/Button.vue';
import Money from '@/components/Money.vue';
import { suggestedDeposit, type ShiftSummaryData } from '@/lib/shift';

const props = defineProps<{
    open: boolean;
    shift: ShiftSummaryData | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    branch_id: props.shift?.branch_id ?? null,
    counted_cash: '',
    cash_deposited: '',
    closing_note: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open || !props.shift) {
            return;
        }

        form.branch_id = props.shift.branch_id;
        form.counted_cash = String(props.shift.expected_cash ?? 0);
        form.cash_deposited = String(
            suggestedDeposit(
                props.shift.expected_cash ?? 0,
                props.shift.opening_cash,
            ),
        );
        form.closing_note = '';
        form.clearErrors();
    },
);

const counted = computed(() => {
    const value = Number(form.counted_cash);
    return Number.isFinite(value) && value >= 0 ? value : 0;
});

const variance = computed(
    () => counted.value - (props.shift?.expected_cash ?? 0),
);

const deposit = computed(() => {
    const value = Number(form.cash_deposited);
    return Number.isFinite(value) && value >= 0 ? value : 0;
});

function submit(): void {
    form.post('/pos/shift/close', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && shift"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-label="Tutup shift"
            @click.self="emit('close')"
        >
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-semibold text-ink">Tutup shift</h2>
                <p class="mt-1 text-sm text-muted">
                    Kas seharusnya
                    <Money :value="shift.expected_cash ?? 0" />. Masukkan uang fisik
                    sebelum setoran.
                </p>

                <label class="mt-4 block">
                    <span class="mb-1.5 block text-sm font-medium text-ink">Kas dihitung</span>
                    <input
                        v-model="form.counted_cash"
                        type="number"
                        min="0"
                        step="1"
                        class="min-h-12 w-full rounded-xl border border-line px-3.5 text-right text-lg font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    />
                    <span v-if="form.errors.counted_cash" class="mt-1 block text-sm text-danger">
                        {{ form.errors.counted_cash }}
                    </span>
                </label>

                <label class="mt-3 block">
                    <span class="mb-1.5 block text-sm font-medium text-ink">Setoran</span>
                    <input
                        v-model="form.cash_deposited"
                        type="number"
                        min="0"
                        step="1"
                        class="min-h-12 w-full rounded-xl border border-line px-3.5 text-right text-lg font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    />
                    <span class="mt-1 block text-xs text-muted">
                        Saran: <Money :value="suggestedDeposit(counted, shift.opening_cash)" />
                    </span>
                    <span v-if="form.errors.cash_deposited" class="mt-1 block text-sm text-danger">
                        {{ form.errors.cash_deposited }}
                    </span>
                </label>

                <div class="mt-3 flex items-center justify-between rounded-xl bg-canvas px-3 py-2 text-sm">
                    <span class="text-muted">Selisih</span>
                    <span class="font-semibold" :class="variance === 0 ? 'text-success' : 'text-danger'">
                        <Money :value="variance" />
                    </span>
                </div>
                <p class="mt-1 text-xs text-muted">
                    Float dipertahankan: <Money :value="counted - deposit" />
                </p>

                <label class="mt-3 block">
                    <span class="mb-1.5 block text-sm font-medium text-ink">
                        Catatan {{ variance !== 0 ? '(wajib)' : '(opsional)' }}
                    </span>
                    <textarea
                        v-model="form.closing_note"
                        rows="3"
                        class="w-full rounded-xl border border-line px-3.5 py-3 text-base text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                        placeholder="Contoh: selisih kurang Rp5.000"
                    />
                    <span v-if="form.errors.closing_note" class="mt-1 block text-sm text-danger">
                        {{ form.errors.closing_note }}
                    </span>
                </label>
                <p v-if="(form.errors as Record<string, string>).shift" class="mt-2 text-sm text-danger">
                    {{ (form.errors as Record<string, string>).shift }}
                </p>

                <div class="mt-5 flex gap-2">
                    <Button variant="secondary" class="flex-1" :disabled="form.processing" @click="emit('close')">
                        Batal
                    </Button>
                    <Button class="flex-1" :disabled="form.processing" @click="submit">
                        {{ form.processing ? 'Menutup…' : 'Tutup shift' }}
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
