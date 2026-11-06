/** Today as YYYY-MM-DD in the local zone (UTC would give Venezuela tomorrow after 8 p.m.). */
export function todayIso(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
