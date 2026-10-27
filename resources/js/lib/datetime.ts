import { formatJakartaDateTime } from '@/lib/date';

export function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return formatJakartaDateTime(date);
}

export function formatElapsed(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const minutes = Math.max(
        0,
        Math.floor((Date.now() - date.getTime()) / 60_000),
    );

    if (minutes < 1) {
        return 'Baru saja';
    }

    if (minutes < 60) {
        return `${minutes} mnt`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} j` : `${hours} j ${rest} mnt`;
}
