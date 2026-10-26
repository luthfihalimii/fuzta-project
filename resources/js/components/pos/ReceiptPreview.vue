<script setup lang="ts">
import Button from '@/components/Button.vue';
import Money from '@/components/Money.vue';
import { formatDateTime } from '@/lib/datetime';
import type { PosOrder } from '@/lib/order';
import { formatSignedRupiah } from '@/lib/money';
import {
    channelLabel,
    paymentMethodLabel,
    paymentStatusLabel,
} from '@/lib/status';

withDefaults(
    defineProps<{
        open: boolean;
        order: PosOrder | null;
        allowNewOrder?: boolean;
    }>(),
    {
        allowNewOrder: false,
    },
);

const emit = defineEmits<{
    close: [];
    newOrder: [];
}>();
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && order"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-label="Struk pesanan"
            @click.self="emit('close')"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white shadow-xl">
                <div
                    class="max-h-[80vh] overflow-y-auto px-5 py-6 font-mono text-sm text-ink"
                >
                    <div class="text-center">
                        <p class="text-lg font-bold">
                            {{ order.branch?.name ?? 'Pizza Fruzta' }}
                        </p>
                        <p
                            v-if="order.branch?.address"
                            class="mt-1 text-xs text-muted"
                        >
                            {{ order.branch.address }}
                        </p>
                        <p
                            v-if="order.branch?.phone"
                            class="text-xs text-muted"
                        >
                            {{ order.branch.phone }}
                        </p>
                    </div>

                    <div class="my-4 border-t border-dashed border-line" />

                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between gap-3">
                            <span class="text-muted">No. pesanan</span>
                            <span class="font-semibold">{{
                                order.order_number
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-muted">Waktu</span>
                            <span>{{ formatDateTime(order.created_at) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-muted">Tipe</span>
                            <span>{{
                                channelLabel(order.channel)
                            }}</span>
                        </div>
                        <div
                            v-if="order.table_number"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-muted">Meja</span>
                            <span>{{ order.table_number }}</span>
                        </div>
                        <div
                            v-if="order.customer_name"
                            class="flex justify-between gap-3"
                        >
                            <span class="text-muted">Nama</span>
                            <span>{{ order.customer_name }}</span>
                        </div>
                    </div>

                    <div class="my-4 border-t border-dashed border-line" />

                    <div class="space-y-3">
                        <div
                            v-for="item in order.items"
                            :key="item.id"
                            class="text-xs"
                        >
                            <div class="flex justify-between gap-3">
                                <span class="font-semibold">
                                    {{ item.quantity }}× {{ item.product_name }}
                                </span>
                                <Money :value="item.line_total" />
                            </div>
                            <p
                                v-if="item.variant_name"
                                class="text-muted"
                            >
                                {{ item.variant_name }}
                            </p>
                            <p
                                v-for="(modifier, index) in item.modifiers"
                                :key="`${item.id}-${index}`"
                                class="text-muted"
                            >
                                + {{ modifier.modifier_name }}
                                <span v-if="modifier.price_delta !== 0">
                                    ({{
                                        formatSignedRupiah(
                                            modifier.price_delta,
                                        )
                                    }})
                                </span>
                            </p>
                            <p v-if="item.note" class="text-muted italic">
                                Catatan: {{ item.note }}
                            </p>
                        </div>
                    </div>

                    <div class="my-4 border-t border-dashed border-line" />

                    <div class="space-y-1">
                        <div class="flex justify-between gap-3">
                            <span>Subtotal</span>
                            <Money :value="order.subtotal" />
                        </div>
                        <div class="flex justify-between gap-3 text-base font-bold">
                            <span>Total</span>
                            <Money :value="order.total" />
                        </div>
                    </div>

                    <div class="my-4 border-t border-dashed border-line" />

                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between gap-3">
                            <span class="text-muted">Status</span>
                            <span class="font-semibold">{{
                                paymentStatusLabel(order.payment_status)
                            }}</span>
                        </div>
                        <template v-if="order.payment">
                            <div class="flex justify-between gap-3">
                                <span class="text-muted">Metode</span>
                                <span>{{
                                    paymentMethodLabel(order.payment.method)
                                }}</span>
                            </div>
                            <div
                                v-if="order.payment.cash_received !== null"
                                class="flex justify-between gap-3"
                            >
                                <span class="text-muted">Tunai</span>
                                <Money :value="order.payment.cash_received" />
                            </div>
                            <div
                                v-if="order.payment.change_amount !== null"
                                class="flex justify-between gap-3"
                            >
                                <span class="text-muted">Kembalian</span>
                                <Money :value="order.payment.change_amount" />
                            </div>
                        </template>
                    </div>

                    <div class="my-4 border-t border-dashed border-line" />

                    <p class="text-center text-xs text-muted">
                        Terima kasih telah memesan di Pizza Fruzta.
                    </p>
                </div>

                <div class="flex gap-2 border-t border-line p-4">
                    <Button
                        v-if="allowNewOrder"
                        class="flex-1"
                        @click="emit('newOrder')"
                    >
                        Pesanan baru
                    </Button>
                    <Button
                        variant="secondary"
                        class="flex-1"
                        @click="emit('close')"
                    >
                        Tutup
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
