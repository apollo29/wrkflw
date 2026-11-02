import { formatDate, formatDateTime, formatShort } from './format';

describe('format', () => {
  it('writes dates the Swiss way and tolerates empty values', () => {
    const at = new Date(2026, 9, 28, 9, 4).toISOString();
    expect(formatDate(at)).toBe('28.10.2026');
    expect(formatDateTime(at)).toBe('28.10.2026 09:04');
    expect(formatShort(at)).toBe('28.10. 09:04');
    expect(formatDate(null)).toBe('');
    expect(formatDateTime('kaputt')).toBe('');
  });
});
