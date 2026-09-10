/** Reads a server-rendered `<script type="application/json">` payload. */

// Keyed by node rather than id: Turbo swaps the body and Stimulus reconnects before any
// turbo:load hook runs, so an id-keyed entry would outlive the page it was parsed from.
const cache = new WeakMap();

export function readPageData(elementId) {
    const node = document.getElementById(elementId);
    if (!node) return {};
    if (cache.has(node)) return cache.get(node);

    let data = {};
    try {
        data = JSON.parse(node.textContent) || {};
    } catch (e) {
        console.error(`Malformed page data in #${elementId}`, e);
    }
    cache.set(node, data);
    return data;
}
