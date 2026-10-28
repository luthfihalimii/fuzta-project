export type ShiftStatus = 'open' | 'auto_closed_unreconciled' | 'closed';

export type ShiftSummaryData = {
    id: number;
    branch_id: number;
    user_id: number;
    user_name: string | null;
    window_key: string;
    scheduled_start_at: string | null;
    scheduled_end_at: string | null;
    opened_at: string | null;
    closed_at: string | null;
    opening_cash: number;
    cash_sales_total: number | null;
    qris_sales_total: number | null;
    cash_in_total: number | null;
    cash_out_total: number | null;
    expected_cash: number | null;
    counted_cash: number | null;
    variance: number | null;
    cash_deposited: number | null;
    closing_note: string | null;
    close_type: 'manual' | 'auto' | null;
    reconciled_at: string | null;
    reconciliation_note: string | null;
    status: ShiftStatus;
};

export type ShiftWindow = {
    key: string;
    label: string;
    start: string;
    end: string;
};

export type ShiftReminder = {
    level: 'ending' | 'overdue' | 'cutoff';
    message: string;
};

export function suggestedDeposit(
    countedCash: number,
    openingCash: number,
): number {
    return Math.max(0, countedCash - openingCash);
}

export function shiftStatusLabel(
    shift: Pick<ShiftSummaryData, 'closed_at' | 'close_type' | 'reconciled_at'>,
): string {
    if (shift.closed_at === null) {
        return 'Terbuka';
    }

    if (shift.close_type === 'auto' && shift.reconciled_at === null) {
        return 'Perlu rekonsiliasi';
    }

    return 'Ditutup';
}

export function shiftStatusTone(
    shift: Pick<ShiftSummaryData, 'closed_at' | 'close_type' | 'reconciled_at'>,
): 'success' | 'warning' | 'neutral' {
    if (shift.closed_at === null) {
        return 'success';
    }

    if (shift.close_type === 'auto' && shift.reconciled_at === null) {
        return 'warning';
    }

    return 'neutral';
}
