export type Tone = 'neutral' | 'success' | 'danger' | 'warning' | 'info';

export const paymentMethods = [
    { value: 'cash', label: 'Tunai' },
    { value: 'qris_static', label: 'QRIS statis' },
] as const;

export function orderStatusLabel(status: string): string {
    return (
        {
            queued: 'Antrian',
            accepted: 'Diterima',
            preparing: 'Disiapkan',
            ready: 'Siap',
            completed: 'Selesai',
            cancelled: 'Dibatalkan',
        }[status] ?? status
    );
}

export function orderStatusTone(status: string): Tone {
    return ({
        queued: 'warning',
        accepted: 'info',
        preparing: 'info',
        ready: 'success',
        completed: 'success',
        cancelled: 'danger',
    }[status] ?? 'neutral') as Tone;
}

export function paymentStatusLabel(status: string): string {
    return (
        {
            unpaid: 'Belum dibayar',
            pending: 'Menunggu pembayaran',
            paid: 'Lunas',
            voided: 'Dibatalkan',
        }[status] ?? status
    );
}

export function paymentStatusTone(status: string): Tone {
    return ({
        unpaid: 'warning',
        pending: 'warning',
        paid: 'success',
        voided: 'danger',
    }[status] ?? 'neutral') as Tone;
}

export function channelLabel(channel: string): string {
    return (
        {
            dine_in: 'Dine-in',
            takeaway: 'Takeaway',
            delivery: 'Delivery',
        }[channel] ?? channel
    );
}

// Status pengiriman Biteship (nilai mentah dari webhook; unknown = apa adanya).
export function deliveryStatusLabel(status: string | null): string {
    if (status === null) {
        return '—';
    }

    return (
        {
            requested: 'Menunggu kurir',
            confirmed: 'Dikonfirmasi',
            scheduled: 'Dijadwalkan',
            allocated: 'Dialokasi',
            picking_up: 'Sedang dijemput',
            picked: 'Ditarik kurir',
            on_hold: 'Ditahan',
            in_transit: 'Dalam perjalanan',
            dropping_off: 'Sedang diantar',
            delivered: 'Terkirim',
            return_in_transit: 'Dikembalikan',
            returned: 'Dikembalikan',
            rejected: 'Ditolak kurir',
            disposed: 'Dibuang',
            courier_not_found: 'Kurir tidak ditemukan',
            cancelled: 'Pengiriman dibatalkan',
            failed: 'Gagal dikirim',
        }[status] ?? status
    );
}

export function deliveryStatusTone(status: string | null): Tone {
    if (status === 'delivered') {
        return 'success';
    }

    if (
        status === 'cancelled' ||
        status === 'failed' ||
        status === 'returned' ||
        status === 'rejected' ||
        status === 'disposed' ||
        status === 'courier_not_found'
    ) {
        return 'danger';
    }

    if (
        status === 'requested' ||
        status === 'on_hold' ||
        status === 'return_in_transit'
    ) {
        return 'warning';
    }

    if (status === null) {
        return 'neutral';
    }

    return 'info';
}

// Jangan memasukkan metode online ke paymentMethods: array itu juga tombol PaymentSheet POS.
const otherMethodLabels: Record<string, string> = {
    doku_qris: 'QRIS online (DOKU)',
};

export function paymentMethodLabel(method: string | null): string {
    if (method === null) {
        return '—';
    }

    return (
        paymentMethods.find((item) => item.value === method)?.label ??
        otherMethodLabels[method] ??
        method
    );
}
