<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/components/Button.vue';
import Money from '@/components/Money.vue';
import { HttpError, postJson } from '@/lib/http';
import type { PosOrder } from '@/lib/order';
import { paymentMethods } from '@/lib/status';

const props = defineProps<{
    open: boolean;
    order: PosOrder | null;
}>();

const emit = defineEmits<{
    paid: [order: PosOrder];
    close: [];
}>();

const method = ref('cash');
const cashReceived = ref('');
const processing = ref(false);
const errorMessage = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open || !props.order) {
            return;
        }

        method.value = 'cash';
        cashReceived.value = String(props.order.total);
        processing.value = false;
        errorMessage.value = '';
    },
);

const cashAmount = computed(() => {
    const value = Number(cashReceived.value);

    return Number.isFinite(value) && value >= 0 ? value : 0;
});

const change = computed(() => {
    if (!props.order || method.value !== 'cash' || cashReceived.value === '') {
        return null;
    }

    return cashAmount.value - props.order.total;
});

const quickAmounts = computed(() => {
    if (!props.order) {
        return [];
    }

    const total = props.order.total;

    return [
        ...new Set(
            [total, 50000, 100000, 200000, 500000, 1000000].filter(
                (value) => value >= total,
            ),
        ),
    ]
        .sort((left, right) => left - right)
        .slice(0, 4);
});

function close(): void {
    if (!processing.value) {
        emit('close');
    }
}

function selectMethod(value: string): void {
    method.value = value;
    errorMessage.value = '';
}

async function pay(): Promise<void> {
    if (!props.order || processing.value) {
        return;
    }

    if (
        method.value === 'cash' &&
        cashReceived.value !== '' &&
        cashAmount.value < props.order.total
    ) {
        errorMessage.value = 'Uang diterima kurang dari total pesanan.';

        return;
    }

    processing.value = true;
    errorMessage.value = '';

    try {
        const response = await postJson<{ order: PosOrder }>(
            `/orders/${props.order.id}/pay`,
            {
                method: method.value,
                cash_received:
                    method.value === 'cash' && cashReceived.value !== ''
                        ? cashAmount.value
                        : null,
            },
        );

        emit('paid', response.order);
    } catch (error) {
        errorMessage.value =
            error instanceof HttpError
                ? error.firstError()
                : 'Pembayaran gagal. Coba lagi.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && order"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-label="Pembayaran"
            @click.self="close"
        >
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-ink">
                            Pembayaran
                        </h2>
                        <p class="mt-0.5 text-sm text-muted">
                            {{ order.order_number }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="min-h-10 rounded-xl px-3 text-sm font-semibold text-muted hover:bg-canvas"
                        :disabled="processing"
                        @click="close"
                    >
                        Tutup
                    </button>
                </div>

                <div class="mt-5 rounded-2xl bg-canvas p-4">
                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">
                        Total tagihan
                    </p>
                    <p class="mt-1 text-3xl font-bold text-ink">
                        <Money :value="order.total" />
                    </p>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button
                        v-for="option in paymentMethods"
                        :key="option.value"
                        type="button"
                        class="min-h-12 rounded-xl border px-3 text-sm font-semibold transition"
                        :class="
                            method === option.value
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-white text-ink hover:bg-canvas'
                        "
                        :aria-pressed="method === option.value"
                        @click="selectMethod(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div v-if="method === 'cash'" class="mt-4 space-y-3">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">
                            Uang diterima
                        </span>
                        <input
                            v-model="cashReceived"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            step="1"
                            class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-right text-lg font-semibold text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="amount in quickAmounts"
                            :key="amount"
                            type="button"
                            class="min-h-10 rounded-xl border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-canvas"
                            @click="cashReceived = String(amount)"
                        >
                            <Money :value="amount" />
                        </button>
                    </div>

                    <div
                        v-if="change !== null"
                        class="flex items-center justify-between rounded-xl px-3 py-2 text-sm font-semibold"
                        :class="
                            change < 0
                                ? 'bg-danger/10 text-danger'
                                : 'bg-success/10 text-success'
                        "
                    >
                        <span>{{
                            change < 0 ? 'Kurang' : 'Kembalian'
                        }}</span>
                        <Money :value="Math.abs(change)" />
                    </div>
                </div>

                <div
                    v-else
                    class="mt-4 rounded-xl border border-info/20 bg-info/10 px-4 py-3 text-sm text-info"
                >
                    Pastikan pembayaran QRIS sudah masuk sebelum mengonfirmasi.
                </div>

                <div
                    v-if="errorMessage"
                    class="mt-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
                >
                    {{ errorMessage }}
                </div>

                <div class="mt-5 flex gap-2">
                    <Button
                        variant="secondary"
                        class="flex-1"
                        :disabled="processing"
                        @click="close"
                    >
                        Batal
                    </Button>
                    <Button
                        class="flex-1"
                        :disabled="processing"
                        @click="pay"
                    >
                        {{ processing ? 'Memproses…' : 'Konfirmasi' }}
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
