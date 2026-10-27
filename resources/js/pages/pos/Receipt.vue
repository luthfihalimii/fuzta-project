<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Button.vue';
import { formatJakartaDateTime } from '@/lib/date';
import { formatRupiah, formatSignedRupiah } from '@/lib/money';
import {
    printViaBrowser,
    printViaRawBt,
    type ReceiptPayload,
} from '@/lib/receipt';
import {
    channelLabel,
    paymentMethodLabel,
    paymentStatusLabel,
} from '@/lib/status';

const props = defineProps<{
    receipt: ReceiptPayload;
}>();

const backUrl = computed(() =>
    props.receipt.branch
        ? `/pos/orders?branch=${props.receipt.branch.id}`
        : '/pos/orders',
);
</script>

<template>
    <Head :title="`Struk ${receipt.order_number}`" />

    <main
        class="min-h-screen bg-canvas px-4 py-6 print:min-h-0 print:bg-white print:p-0"
    >
        <div class="mx-auto w-full max-w-[80mm]">
            <div class="mb-4 flex flex-wrap gap-2 print:hidden">
                <Link
                    :href="backUrl"
                    class="inline-flex min-h-12 items-center justify-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                >
                    Kembali
                </Link>
                <Button class="flex-1" @click="printViaBrowser">
                    Cetak browser
                </Button>
                <Button
                    variant="secondary"
                    class="flex-1"
                    @click="printViaRawBt(receipt)"
                >
                    Cetak RawBT
                </Button>
            </div>

            <p class="mb-4 text-xs text-muted print:hidden">
                RawBT hanya tersedia di Android dengan aplikasi RawBT dan
                printer yang sudah dipasangkan. Jika belum ada, gunakan cetak
                browser.
            </p>

            <article
                class="receipt-paper mx-auto w-full max-w-[80mm] bg-white px-5 py-6 font-mono text-xs leading-relaxed text-ink shadow-sm"
            >
                <header
                    class="border-b border-dashed border-line pb-4 text-center"
                >
                    <p class="text-base font-bold tracking-wide">
                        {{ receipt.branch?.name ?? 'Pizza Fruzta' }}
                    </p>
                    <p v-if="receipt.branch?.address" class="mt-1">
                        {{ receipt.branch.address }}
                    </p>
                    <p v-if="receipt.branch?.phone">
                        {{ receipt.branch.phone }}
                    </p>
                </header>

                <div
                    v-if="receipt.payment_status !== 'paid'"
                    class="my-3 border-2 border-dashed border-danger px-3 py-2 text-center text-sm font-bold text-danger"
                >
                    {{
                        paymentStatusLabel(receipt.payment_status).toUpperCase()
                    }}
                </div>

                <dl class="space-y-1 py-3">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">No. pesanan</dt>
                        <dd class="font-semibold">
                            {{ receipt.order_number }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">Waktu</dt>
                        <dd>
                            {{
                                receipt.datetime
                                    ? formatJakartaDateTime(receipt.datetime)
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">Tipe</dt>
                        <dd>{{ channelLabel(receipt.channel) }}</dd>
                    </div>
                    <div
                        v-if="receipt.table_number"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted">Meja</dt>
                        <dd>{{ receipt.table_number }}</dd>
                    </div>
                    <div
                        v-if="receipt.cashier"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted">Kasir</dt>
                        <dd>{{ receipt.cashier }}</dd>
                    </div>
                </dl>

                <div class="border-t border-dashed border-line py-3">
                    <div
                        v-for="(item, index) in receipt.items"
                        :key="index"
                        class="mb-3 last:mb-0"
                    >
                        <div class="flex justify-between gap-3">
                            <p class="font-semibold">
                                {{ item.quantity }}× {{ item.product_name }}
                            </p>
                            <p class="shrink-0 tabular-nums">
                                {{ formatRupiah(item.line_total) }}
                            </p>
                        </div>
                        <p v-if="item.variant_name" class="text-muted">
                            {{ item.variant_name }}
                        </p>
                        <p
                            v-for="(modifier, modifierIndex) in item.modifiers"
                            :key="modifierIndex"
                            class="text-muted"
                        >
                            + {{ modifier.modifier_name }}
                            <span v-if="modifier.price_delta !== 0">
                                ({{ formatSignedRupiah(modifier.price_delta) }})
                            </span>
                        </p>
                        <p v-if="item.note" class="text-muted italic">
                            Catatan: {{ item.note }}
                        </p>
                    </div>
                </div>

                <div class="border-t border-dashed border-line py-3">
                    <div class="flex justify-between gap-3 text-sm font-bold">
                        <span>Total</span>
                        <span class="tabular-nums">
                            {{ formatRupiah(receipt.total) }}
                        </span>
                    </div>
                </div>

                <div class="border-t border-dashed border-line py-3">
                    <div class="flex justify-between gap-3">
                        <span class="text-muted">Status</span>
                        <span class="font-semibold">
                            {{ paymentStatusLabel(receipt.payment_status) }}
                        </span>
                    </div>
                    <div
                        v-if="receipt.payment_method"
                        class="flex justify-between gap-3"
                    >
                        <span class="text-muted">Metode</span>
                        <span>{{
                            paymentMethodLabel(receipt.payment_method)
                        }}</span>
                    </div>
                    <div
                        v-if="receipt.cash_received !== null"
                        class="flex justify-between gap-3"
                    >
                        <span class="text-muted">Tunai</span>
                        <span class="tabular-nums">
                            {{ formatRupiah(receipt.cash_received) }}
                        </span>
                    </div>
                    <div
                        v-if="receipt.change !== null"
                        class="flex justify-between gap-3"
                    >
                        <span class="text-muted">Kembalian</span>
                        <span class="tabular-nums">
                            {{ formatRupiah(receipt.change) }}
                        </span>
                    </div>
                </div>

                <footer
                    class="border-t border-dashed border-line pt-4 text-center"
                >
                    <p>Terima kasih telah memesan</p>
                    <p>di Pizza Fruzta.</p>
                    <p v-if="receipt.payment_status === 'paid'" class="mt-2">
                        Simpan struk ini sebagai bukti pembayaran.
                    </p>
                    <p v-else class="mt-2 font-semibold">
                        Struk ini bukan bukti pembayaran.
                    </p>
                </footer>
            </article>
        </div>
    </main>
</template>

<style scoped>
@media print {
    :global(body) {
        background: #ffffff;
    }

    .receipt-paper {
        width: 80mm;
        max-width: none;
        padding: 0;
        box-shadow: none;
    }

    @page {
        size: 80mm auto;
        margin: 0;
    }
}
</style>
