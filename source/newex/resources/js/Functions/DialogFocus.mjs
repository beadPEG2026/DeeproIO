export function containDialogFocus(dialog, close) {
  const doc = dialog.ownerDocument;
  const previous = doc.activeElement;
  const overflow = doc.body.style.overflow;
  doc.body.style.overflow = 'hidden';
  const items = () => Array.from(dialog.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'))
    .filter(el => el.getClientRects().length > 0 && el.getAttribute('aria-hidden') !== 'true');
  dialog.setAttribute('tabindex', '-1');
  const focusFirst = () => (items()[0] || dialog).focus();
  const onKey = event => {
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
    if (event.key !== 'Tab') return;
    const list = items(); const first = list[0]; const last = list[list.length - 1];
    if (!first) { event.preventDefault(); dialog.focus(); }
    else if (event.shiftKey && (doc.activeElement === first || doc.activeElement === dialog)) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (doc.activeElement === last || doc.activeElement === dialog)) { event.preventDefault(); first.focus(); }
  };
  const onFocus = event => { if (!dialog.contains(event.target)) focusFirst(); };
  dialog.addEventListener('keydown',onKey); doc.addEventListener('focusin',onFocus);
  focusFirst();
  let disposed = false;
  return () => {
    if (disposed) return; disposed = true;
    dialog.removeEventListener('keydown',onKey);doc.removeEventListener('focusin',onFocus);
    doc.body.style.overflow = overflow;
    if (previous && previous.isConnected && typeof previous.focus === 'function') previous.focus();
  };
}
