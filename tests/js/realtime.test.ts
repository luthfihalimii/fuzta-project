import { describe, expect, it } from 'vitest';
import {
    isLate,
    shouldRing,
    type RealtimeNotification,
} from '../../resources/js/lib/realtime';

function notification(
    overrides: Partial<RealtimeNotification> = {},
): RealtimeNotification {
    return {
        kind: 'payment',
        order_number: 'ORD-001',
        branch_id: 1,
        created_at: null,
        accepted_at: null,
        ...overrides,
    };
}

describe('isLate', () => {
    const now = Date.now();
    const minutesAgo = (minutes: number): string =>
        new Date(now - minutes * 60_000).toISOString();

    it('returns false for null start', () => {
        expect(isLate(null, now, 15)).toBe(false);
    });

    it('returns false for an invalid date string', () => {
        expect(isLate('bukan-tanggal', now, 15)).toBe(false);
    });

    it('returns true when past the threshold', () => {
        expect(isLate(minutesAgo(16), now, 15)).toBe(true);
    });

    it('returns false when under the threshold', () => {
        expect(isLate(minutesAgo(14), now, 15)).toBe(false);
    });

    it('returns false exactly at the threshold (strict comparison)', () => {
        expect(isLate(minutesAgo(15), now, 15)).toBe(false);
    });
});

describe('shouldRing', () => {
    it('rings for an unseen payment notification', () => {
        expect(shouldRing(notification(), new Set())).toBe(true);
    });

    it('does not ring for an already-seen order number', () => {
        expect(shouldRing(notification(), new Set(['ORD-001']))).toBe(false);
    });

    it('does not ring for created notifications', () => {
        expect(shouldRing(notification({ kind: 'created' }), new Set())).toBe(
            false,
        );
    });

    it('does not ring for status notifications', () => {
        expect(shouldRing(notification({ kind: 'status' }), new Set())).toBe(
            false,
        );
    });

    it('does not ring for an empty order number', () => {
        expect(shouldRing(notification({ order_number: '' }), new Set())).toBe(
            false,
        );
    });
});
