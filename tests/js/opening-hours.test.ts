import { describe, expect, it } from 'vitest';
import { isOpenNow } from '../../resources/js/lib/opening-hours';

function at(hhmm: string): Date {
    return new Date(
        2026,
        9,
        31,
        Number(hhmm.slice(0, 2)),
        Number(hhmm.slice(3, 5)),
    );
}

describe('isOpenNow', () => {
    it('is false before opening', () => {
        expect(isOpenNow('09:00', '21:00', at('08:59'))).toBe(false);
    });

    it('is true exactly at opening (inclusive)', () => {
        expect(isOpenNow('09:00', '21:00', at('09:00'))).toBe(true);
    });

    it('is true mid-range', () => {
        expect(isOpenNow('09:00', '21:00', at('14:30'))).toBe(true);
    });

    it('is false exactly at closing (exclusive)', () => {
        expect(isOpenNow('09:00', '21:00', at('21:00'))).toBe(false);
    });

    it('is false after closing', () => {
        expect(isOpenNow('09:00', '21:00', at('23:15'))).toBe(false);
    });

    it('is false when the range is inverted', () => {
        expect(isOpenNow('21:00', '09:00', at('12:00'))).toBe(false);
    });
});
