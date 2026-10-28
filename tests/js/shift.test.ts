import { describe, expect, it } from 'vitest';
import { shiftStatusLabel, suggestedDeposit } from '@/lib/shift';

describe('shift helpers', () => {
    it('suggests counted cash minus float, never below zero', () => {
        expect(suggestedDeposit(345000, 200000)).toBe(145000);
        expect(suggestedDeposit(150000, 200000)).toBe(0);
    });

    it('labels shift status', () => {
        expect(
            shiftStatusLabel({
                closed_at: null,
                close_type: null,
                reconciled_at: null,
            }),
        ).toBe('Terbuka');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'auto',
                reconciled_at: null,
            }),
        ).toBe('Perlu rekonsiliasi');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'auto',
                reconciled_at: 'y',
            }),
        ).toBe('Ditutup');
        expect(
            shiftStatusLabel({
                closed_at: 'x',
                close_type: 'manual',
                reconciled_at: 'y',
            }),
        ).toBe('Ditutup');
    });
});
