import { describe, expect, it } from 'vitest';
import { formatRupiah, formatSignedRupiah } from '../../resources/js/lib/money';

describe('formatRupiah', () => {
    it('formats integer rupiah with dot separators', () => {
        expect(formatRupiah(85000)).toBe('Rp 85.000');
        expect(formatRupiah(0)).toBe('Rp 0');
    });

    it('uses a regular space instead of a non-breaking space', () => {
        expect(formatRupiah(1500000)).not.toContain('\u00a0');
    });

    it('formats signed values for modifiers', () => {
        expect(formatSignedRupiah(10000)).toBe('+Rp 10.000');
        expect(formatSignedRupiah(-5000)).toBe('−Rp 5.000');
        expect(formatSignedRupiah(0)).toBe('');
    });
});
