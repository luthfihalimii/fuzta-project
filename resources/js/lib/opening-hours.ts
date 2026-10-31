export function isOpenNow(
    opensAt: string,
    closesAt: string,
    date: Date = new Date(),
): boolean {
    const pad = (n: number): string => String(n).padStart(2, '0');
    const now = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

    // jam device = jam cabang (satu zona); bandingkan H:i leksikografis.
    // Range terbalik (closesAt <= opensAt) selalu Tutup: guard row lama/DB manual.
    return closesAt > opensAt && now >= opensAt && now < closesAt;
}
