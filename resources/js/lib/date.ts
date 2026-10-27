const jakartaDateFormatter = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

const jakartaTimeFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

export function formatJakartaDateTime(value: string | Date): string {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value instanceof Date ? '—' : value;
    }

    return `${jakartaDateFormatter.format(date)}, ${jakartaTimeFormatter.format(date)}`;
}
