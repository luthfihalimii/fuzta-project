export type RealtimeNotification = {
    kind: 'created' | 'status' | 'payment';
    order_number: string;
    branch_id: number;
    created_at: string | null;
    accepted_at: string | null;
    from?: string;
    to?: string;
};

export function isLate(
    startAt: string | null,
    now: number,
    minutes: number,
): boolean {
    if (startAt === null) return false;
    const startMs = new Date(startAt).getTime();
    return !Number.isNaN(startMs) && now - startMs > minutes * 60_000;
}

export function shouldRing(
    n: RealtimeNotification,
    seen: ReadonlySet<string>,
): boolean {
    return (
        n.kind === 'payment' &&
        n.order_number !== '' &&
        !seen.has(n.order_number)
    );
}
