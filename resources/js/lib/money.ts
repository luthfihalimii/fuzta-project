const rupiahFormatter = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
    minimumFractionDigits: 0,
});

export function formatRupiah(value: number): string {
    return rupiahFormatter.format(value).replace(/\u00a0/g, ' ');
}

export function formatSignedRupiah(value: number): string {
    if (value === 0) {
        return '';
    }

    return `${value > 0 ? '+' : '−'}${formatRupiah(Math.abs(value))}`;
}
