/**
 * Defers chart construction until the canvas is near the viewport. The stock page declares 38
 * canvases across its tabs; those in a hidden panel or a filtered-out card never intersect.
 */

/** Lead time, so a chart is drawn by the time its canvas lands. */
const ROOT_MARGIN = '200px';

/** Canvas id -> the newest render closure awaiting that canvas. */
const pending = new Map();

let observer = null;

function ensureObserver() {
    if (observer) return observer;
    if (typeof IntersectionObserver === 'undefined') return null;

    observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const render = pending.get(entry.target.id);
            observer.unobserve(entry.target);
            pending.delete(entry.target.id);
            if (!render) return;

            // Isolate the batch: forEach would abandon the remaining entries on a throw.
            try {
                render();
            } catch (err) {
                console.error(`Chart "${entry.target.id}" failed to render`, err);
            }
        });
    }, { rootMargin: ROOT_MARGIN });

    return observer;
}

export function renderWhenVisible(canvasId, render) {
    const el = document.getElementById(canvasId);
    if (!el) return;

    const obs = ensureObserver();
    if (!obs) {
        render();
        return;
    }

    // A re-render (timeframe change, say) supersedes any closure still waiting on this canvas.
    pending.set(canvasId, render);
    obs.observe(el);
}

/** Drops every pending render; called when the page tears its charts down. */
export function resetLazyCharts() {
    if (observer) {
        observer.disconnect();
        observer = null;
    }
    pending.clear();
}
