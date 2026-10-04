export function supportTime(value) { if (!value) return '—'; const date=new Date(value); return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString(undefined,{hour12:false}); }
