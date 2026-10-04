// Display only. Database IDs, invitation codes and transfer recipients stay unchanged.
export function formatAccountUid(id) {
    if (typeof id === 'number' && (!Number.isSafeInteger(id) || id <= 0)) return '—';
    if (!['string', 'number', 'bigint'].includes(typeof id)) return '—';
    const value = String(id);
    if (!/^\d+$/.test(value)) return '—';
    const digits = value.replace(/^0+(?=\d)/, '');
    return digits === '0' ? '—' : digits.padStart(6, '0');
}
