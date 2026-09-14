/** Green/red flash marking a figure that moved on a tick. */

const FLASH_DURATION_MS = 500;

// Every flashed element and when its colour should be restored. One sweep timer serves them
// all: the previous version armed (and cancelled) a timer per element per tick, which on the
// market table was hundreds of timers a second for nothing.
const expiries = new Map();
let sweepTimer = null;

export function flashTick(el, direction) {
    if (!el) return;

    el.classList.remove('tick-up', 'tick-down');
    if (direction > 0) {
        el.classList.add('tick-up');
    } else if (direction < 0) {
        el.classList.add('tick-down');
    } else {
        expiries.delete(el);
        return;
    }

    expiries.set(el, Date.now() + FLASH_DURATION_MS);
    if (sweepTimer === null) {
        sweepTimer = setTimeout(sweep, FLASH_DURATION_MS);
    }
}

/** Restores every element whose flash has run its course, then re-arms for the next one due. */
function sweep() {
    sweepTimer = null;
    const now = Date.now();
    let nextDue = Infinity;

    expiries.forEach((expiry, el) => {
        if (expiry <= now) {
            el.classList.remove('tick-up', 'tick-down');
            expiries.delete(el);
        } else {
            nextDue = Math.min(nextDue, expiry);
        }
    });

    if (nextDue !== Infinity) {
        sweepTimer = setTimeout(sweep, Math.max(16, nextDue - now));
    }
}
