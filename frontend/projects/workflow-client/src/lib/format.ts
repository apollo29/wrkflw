/** Datum und Zeit für die Oberfläche (Schweizer Schreibweise, lokale Zeitzone). */

function parse(value: string | null | undefined): Date | null {
  if (!value) {
    return null;
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

const pad = (n: number): string => String(n).padStart(2, '0');

/** `28.10.2026` */
export function formatDate(value: string | null | undefined): string {
  const d = parse(value);
  return d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '';
}

/** `28.10.2026 09:14` */
export function formatDateTime(value: string | null | undefined): string {
  const d = parse(value);
  return d ? `${formatDate(value)} ${pad(d.getHours())}:${pad(d.getMinutes())}` : '';
}

/** `30.10. 16:31` (Verlauf) */
export function formatShort(value: string | null | undefined): string {
  const d = parse(value);
  return d
    ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}. ${pad(d.getHours())}:${pad(d.getMinutes())}`
    : '';
}
