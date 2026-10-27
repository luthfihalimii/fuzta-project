import { describe, expect, it } from 'vitest';
import {
    rawBtIntentUrl,
    toEscPos,
    type ReceiptPayload,
} from '../../resources/js/lib/receipt';

const payload: ReceiptPayload = {
    branch: {
        id: 1,
        code: 'PF-01',
        name: 'Pizza Fruzta Pusat',
        address: 'Jl. Merdeka 1',
        phone: '021-123456',
        is_active: true,
    },
    order_number: 'PF-20261026-0001',
    datetime: '2026-10-26T07:30:00Z',
    channel: 'dine_in',
    table_number: 'A-04',
    items: [
        {
            product_name: 'Pizza Margherita',
            variant_name: 'Large',
            quantity: 2,
            unit_price: 85000,
            line_total: 170000,
            note: 'Tanpa bawang',
            modifiers: [
                {
                    modifier_name: 'Extra Cheese',
                    price_delta: 5000,
                },
            ],
        },
    ],
    total: 170000,
    payment_status: 'paid',
    payment_method: 'cash',
    cashier: 'Sari',
    cash_received: 200000,
    change: 30000,
};

describe('toEscPos', () => {
    it('builds a printable receipt with ESC/POS init and cut commands', () => {
        const output = toEscPos(payload);

        expect(output.startsWith('\x1B@')).toBe(true);
        expect(output).toContain('PIZZA FRUZTA');
        expect(output).toContain('Pizza Fruzta Pusat');
        expect(output).toContain('PF-20261026-0001');
        expect(output).toContain('2x Pizza Margherita');
        expect(output).toContain('Large');
        expect(output).toContain('+ Extra Cheese (+Rp 5.000)');
        expect(output).toContain('Catatan: Tanpa bawang');
        expect(output).toContain('Rp 170.000');
        expect(output).toContain('Tunai');
        expect(output).toContain('Rp 200.000');
        expect(output).toContain('Rp 30.000');
        expect(output).toContain('\x1DV\x00');
    });

    it('uses an ASCII-safe 32-column layout by default', () => {
        const output = toEscPos(payload);

        expect(output).toContain('-'.repeat(32));
        expect(output).not.toContain('×');
        expect(output).not.toContain('−');
    });

    it('supports an 80mm 42-column layout', () => {
        const output = toEscPos(payload, 42);

        expect(output).toContain('-'.repeat(42));
    });
});

describe('rawBtIntentUrl', () => {
    it('builds an Android intent for the RawBT package', () => {
        const url = rawBtIntentUrl(payload);

        expect(url.startsWith('intent:base64,')).toBe(true);
        expect(url).toContain('#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end');
    });
});
