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
    return (
        {
            queued: 'warning',
            accepted: 'info',
            preparing: 'info',
            ready: 'success',
            completed: 'success',
            cancelled: 'danger',
        }[status] ?? 'neutral'
    ) as Tone;
}

export function paymentStatusLabel(status: string): string {
    return (
        {
            unpaid: 'Belum dibayar',
            paid: 'Lunas',
            voided: 'Dibatalkan',
        }[status] ?? status
    );
}

export function paymentStatusTone(status: string): Tone {
    return (
        {
            unpaid: 'warning',
            paid: 'success',
            voided: 'danger',
        }[status] ?? 'neutral'
    ) as Tone;
}

export function channelLabel(channel: string): string {
    return (
        {
            dine_in: 'Dine-in',
            takeaway: 'Takeaway',
        }[channel] ?? channel
    );
}

export function paymentMethodLabel(method: string | null): string {
    if (method === null) {
        return '—';
    }

    return paymentMethods.find((item) => item.value === method)?.label ?? method;
}
