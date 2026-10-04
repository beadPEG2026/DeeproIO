// Keep the selection inside the active native dialog: the rest of the page is inert.
export async function copyText(value, container, environment = globalThis) {
    const text = String(value ?? '');
    if (!text) throw new Error('empty_clipboard_value');
    const doc = environment.document;
    try {
        if (environment.navigator?.clipboard?.writeText) {
            await environment.navigator.clipboard.writeText(text);
            return;
        }
    } catch (_) { /* Permission denied: try the user-initiated selection path. */ }
    const active = doc.activeElement;
    const selection = doc.getSelection?.();
    const ranges = selection ? Array.from({length: selection.rangeCount}, (_, i) => selection.getRangeAt(i).cloneRange()) : [];
    const inputSelection = typeof active?.selectionStart === 'number' ? [active.selectionStart, active.selectionEnd] : null;
    const dialog = active?.closest?.('dialog[open]') || Array.from(doc.querySelectorAll('dialog[open]')).pop();
    const root = dialog || container || doc.body;
    const input = doc.createElement('textarea');
    input.value = text;
    input.readOnly = true;
    input.tabIndex = -1;
    input.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;pointer-events:none';
    root.appendChild(input);
    try {
        input.focus({preventScroll: true});
        input.select();
        input.setSelectionRange(0, text.length);
        if (!doc.execCommand('copy')) throw new Error('clipboard_unavailable');
    } finally {
        input.remove();
        active?.focus?.({preventScroll: true});
        if (inputSelection) active.setSelectionRange(...inputSelection);
        if (selection) { selection.removeAllRanges(); ranges.forEach(range => selection.addRange(range)); }
    }
}
