import { describe, expect, it } from 'vitest';
import {
    channelLabel,
    orderStatusLabel,
    orderStatusTone,
    paymentMethodLabel,
    paymentStatusLabel,
    paymentStatusTone,
} from '../../resources/js/lib/status';

describe('status labels', () => {
    it('labels order statuses in Bahasa Indonesia', () => {
        expect(orderStatusLabel('queued')).toBe('Antrian');
        expect(orderStatusLabel('ready')).toBe('Siap');
        expect(orderStatusLabel('cancelled')).toBe('Dibatalkan');
    });

    it('labels payment statuses separately from order statuses', () => {
        expect(paymentStatusLabel('unpaid')).toBe('Belum dibayar');
        expect(paymentStatusLabel('paid')).toBe('Lunas');
        expect(paymentStatusLabel('voided')).toBe('Dibatalkan');
    });

    it('labels channels and payment methods', () => {
        expect(channelLabel('dine_in')).toBe('Dine-in');
        expect(channelLabel('takeaway')).toBe('Takeaway');
        expect(paymentMethodLabel('cash')).toBe('Tunai');
        expect(paymentMethodLabel('qris_static')).toBe('QRIS statis');
        expect(paymentMethodLabel(null)).toBe('—');
    });
});

describe('status tones', () => {
    it('maps statuses to semantic tones with text labels', () => {
        expect(orderStatusTone('queued')).toBe('warning');
        expect(orderStatusTone('accepted')).toBe('info');
        expect(orderStatusTone('ready')).toBe('success');
        expect(orderStatusTone('cancelled')).toBe('danger');
        expect(paymentStatusTone('paid')).toBe('success');
        expect(paymentStatusTone('voided')).toBe('danger');
    });
});
