import { formatJakartaDateTime } from './date';
import { formatRupiah, formatSignedRupiah } from './money';
import type { BranchSummary, OrderItemModifier } from './order';
import {
    channelLabel,
    paymentMethodLabel,
    paymentStatusLabel,
} from './status';

export type ReceiptModifier = OrderItemModifier;

export type ReceiptItem = {
    product_name: string;
    variant_name: string | null;
    quantity: number;
    unit_price: number;
    line_total: number;
    note: string | null;
    modifiers: ReceiptModifier[];
};

export type ReceiptPayload = {
    branch: BranchSummary | null;
    order_number: string;
    datetime: string | null;
    channel: string;
    table_number: string | null;
    items: ReceiptItem[];
    total: number;
    payment_status: string;
    payment_method: string | null;
    cashier: string | null;
    cash_received: number | null;
    change: number | null;
};

const ESC = '\x1B';
const GS = '\x1D';

function normalizeText(value: string): string {
    return value
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[\u00a0\u2007\u202f]/g, ' ')
        .replace(/[×✕]/g, 'x')
        .replace(/[−–—]/g, '-')
        .replace(/[^\x20-\x7E\n]/g, '');
}

function wrapText(value: string, columns: number): string[] {
    const words = normalizeText(value).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return [''];
    }

    const lines: string[] = [];
    let current = '';

    for (const word of words) {
        if (word.length > columns) {
            if (current !== '') {
                lines.push(current);
                current = '';
            }

            for (let index = 0; index < word.length; index += columns) {
                lines.push(word.slice(index, index + columns));
            }

            continue;
        }

        const next = current === '' ? word : `${current} ${word}`;

        if (next.length <= columns) {
            current = next;
        } else {
            lines.push(current);
            current = word;
        }
    }

    if (current !== '') {
        lines.push(current);
    }

    return lines;
}

function center(value: string, columns: number): string {
    const text = normalizeText(value);

    if (text.length >= columns) {
        return text.slice(0, columns);
    }

    return `${' '.repeat(Math.floor((columns - text.length) / 2))}${text}`;
}

function pair(label: string, value: string, columns: number): string[] {
    const left = normalizeText(label);
    const right = normalizeText(value);

    if (left.length + right.length + 1 <= columns) {
        return [`${left}${' '.repeat(columns - left.length - right.length)}${right}`];
    }

    return [left, right.padStart(columns)];
}

function separator(columns: number): string {
    return '-'.repeat(columns);
}

export function toEscPos(payload: ReceiptPayload, columns = 32): string {
    const lines: string[] = [];
    const push = (...items: string[]) => lines.push(...items);

    push(`${ESC}@`);
    push(`${ESC}a${String.fromCharCode(1)}`);
    push(`${ESC}E${String.fromCharCode(1)}`);
    push(center('PIZZA FRUZTA', columns));
    push(`${ESC}E${String.fromCharCode(0)}`);

    if (payload.branch) {
        push(center(payload.branch.name, columns));

        if (payload.branch.address) {
            push(...wrapText(payload.branch.address, columns));
        }

        if (payload.branch.phone) {
            push(...wrapText(payload.branch.phone, columns));
        }
    }

    push(`${ESC}a${String.fromCharCode(0)}`);
    push(separator(columns));
    push(...pair('No.', payload.order_number, columns));
    push(
        ...pair(
            'Waktu',
            payload.datetime
                ? formatJakartaDateTime(payload.datetime)
                : '-',
            columns,
        ),
    );
    push(...pair('Tipe', channelLabel(payload.channel), columns));

    if (payload.table_number) {
        push(...pair('Meja', payload.table_number, columns));
    }

    if (payload.cashier) {
        push(...pair('Kasir', payload.cashier, columns));
    }

    push(separator(columns));

    for (const item of payload.items) {
        push(...wrapText(`${item.quantity}x ${item.product_name}`, columns));

        if (item.variant_name) {
            push(...wrapText(item.variant_name, columns));
        }

        for (const modifier of item.modifiers) {
            const price =
                modifier.price_delta === 0
                    ? ''
                    : ` (${formatSignedRupiah(modifier.price_delta)})`;
            push(
                ...wrapText(`+ ${modifier.modifier_name}${price}`, columns),
            );
        }

        if (item.note) {
            push(...wrapText(`Catatan: ${item.note}`, columns));
        }

        push(formatRupiah(item.line_total).padStart(columns));
    }

    push(separator(columns));
    push(`${ESC}E${String.fromCharCode(1)}`);
    push(...pair('TOTAL', formatRupiah(payload.total), columns));
    push(`${ESC}E${String.fromCharCode(0)}`);
    push(
        ...pair(
            'Status',
            paymentStatusLabel(payload.payment_status),
            columns,
        ),
    );

    if (payload.payment_method) {
        push(
            ...pair(
                'Metode',
                paymentMethodLabel(payload.payment_method),
                columns,
            ),
        );
    }

    if (payload.cash_received !== null) {
        push(...pair('Tunai', formatRupiah(payload.cash_received), columns));
    }

    if (payload.change !== null) {
        push(...pair('Kembali', formatRupiah(payload.change), columns));
    }

    push(separator(columns));
    push(`${ESC}a${String.fromCharCode(1)}`);
    push(center('Terima kasih telah memesan', columns));
    push(center('di Pizza Fruzta.', columns));
    push('\n\n\n');
    push(`${GS}V${String.fromCharCode(0)}`);

    return lines.join('\n');
}

function utf8ToBase64(value: string): string {
    const bytes = new TextEncoder().encode(value);
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

export function rawBtIntentUrl(payload: ReceiptPayload): string {
    const base64 = utf8ToBase64(toEscPos(payload));

    return `intent:base64,${encodeURIComponent(base64)}#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end`;
}

export function printViaBrowser(): void {
    window.print();
}

export function printViaRawBt(payload: ReceiptPayload): void {
    window.location.href = rawBtIntentUrl(payload);
}
