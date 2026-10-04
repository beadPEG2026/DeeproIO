// Navigation only: no order, market, or balance mutations.
export function tradeViewport(doc, win) {
    const height = win.visualViewport?.height || win.innerHeight;
    const visible = selector => [...doc.querySelectorAll(selector)]
        .map(el => el.getBoundingClientRect())
        .filter(rect => rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < height);
    const headers = visible('.dp-platform-caption, .header-section');
    const top = Math.max(0, ...headers.map(rect => rect.bottom));
    // Ignore the shortcut dock here: hiding it must not change its own visibility test.
    const navs = visible('.dp-bottom-nav');
    const bottom = Math.min(height, ...navs.map(rect => rect.top));
    return { top, bottom };
}
export function ticketScrollTop(rect, scrollY, headerBottom, gap = 8) {
    return Math.max(0, scrollY + rect.top - headerBottom - gap);
}
export function ticketIsVisible(rect, viewport) {
    if (!(rect.width > 0 && rect.height > 0)) return false;
    const available = Math.max(0, Math.min(rect.bottom, viewport.bottom) - Math.max(rect.top, viewport.top));
    return available >= Math.min(160, rect.height);
}

// The initial smooth scroll can outlive Vue's next layout (for example, hiding
// the shortcut dock changes the reserved chart height). Recheck briefly after
// scrolling settles; never keep following live prices or fight user input.
export function positionTradeTicket(ticket, doc, win, onPosition = () => {}) {
    const durationMs = 1800, settleMs = 120, maxCorrections = 3;
    const started = win.performance.now();
    let previousY = win.scrollY, lastMovement = started, corrections = 0;
    let frame, timer, stopped = false;
    const events = ['wheel', 'touchstart', 'pointerdown', 'keydown', 'input', 'focusin', 'blur'];
    const options = { capture: true, passive: true };
    const stop = () => {
        if (stopped) return;
        stopped = true;
        win.cancelAnimationFrame(frame);
        win.clearTimeout(timer);
        events.forEach(name => win.removeEventListener(name, onInteraction, options));
    };
    const onInteraction = event => {
        if (event.type === 'focusin' && event.target === ticket) return;
        stop();
        // Cancel any remaining native smooth animation at its current position.
        // Do not prevent the event: the user's scroll/focus action continues.
        win.scrollTo({ top: win.scrollY, behavior: 'instant' });
    };
    const target = () => ticketScrollTop(ticket.getBoundingClientRect(), win.scrollY, tradeViewport(doc, win).top);
    ticket.focus({ preventScroll: true });
    win.scrollTo({ top: target(), behavior: win.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    onPosition();
    events.forEach(name => win.addEventListener(name, onInteraction, options));
    const check = () => {
        if (stopped) return;
        const now = win.performance.now();
        if (now - started >= durationMs || ticket.isConnected === false) { stop(); return; }
        if (Math.abs(win.scrollY - previousY) > 0.5) {
            previousY = win.scrollY;
            lastMovement = now;
        } else if (now - lastMovement >= settleMs) {
            const top = target();
            if (Math.abs(top - win.scrollY) > 1) {
                // A correction is immediate: it must not start another animation
                // whose destination can become stale during a subsequent layout.
                win.scrollTo({ top, behavior: 'instant' });
                previousY = win.scrollY;
                lastMovement = now;
                onPosition();
                if (++corrections >= maxCorrections) { stop(); return; }
            }
        }
        frame = win.requestAnimationFrame(check);
    };
    frame = win.requestAnimationFrame(check);
    timer = win.setTimeout(stop, durationMs);
    return stop;
}
