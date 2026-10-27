import { describe, expect, it } from 'vitest';
import { formatJakartaDateTime } from '../../resources/js/lib/date';

describe('formatJakartaDateTime', () => {
    it('renders UTC input in Asia/Jakarta', () => {
        expect(formatJakartaDateTime('2026-10-26T07:30:00Z')).toBe(
            '26 Okt 2026, 14:30',
        );
    });

    it('crosses the date boundary correctly', () => {
        expect(formatJakartaDateTime('2026-10-25T17:30:00Z')).toBe(
            '26 Okt 2026, 00:30',
        );
    });

    it('returns the original value when it cannot be parsed', () => {
        expect(formatJakartaDateTime('bukan tanggal')).toBe('bukan tanggal');
    });
});
