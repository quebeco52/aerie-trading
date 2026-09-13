/** Green/red flash marking a figure that moved on a tick. */

const FLASH_DURATION_MS = 500;

// One pending clear per element, so a fast tick supersedes the previous flash rather than
// leaving two timers racing to restore different colours.
const pendingClears = new WeakMap();

export function flashTick(el, direction) {
    if (!el) return;

    const existing = pendingClears.get(el);
    if (existing) clearTimeout(existing);

    el.classList.remove('tick-up', 'tick-down');
    if (direction > 0) {
        el.classList.add('tick-up');
    } else if (direction < 0) {
        el.classList.add('tick-down');
    } else {
        pendingClears.delete(el);
        return;
    }

    pendingClears.set(el, setTimeout(() => {
        el.classList.remove('tick-up', 'tick-down');
        pendingClears.delete(el);
    }, FLASH_DURATION_MS));
}
