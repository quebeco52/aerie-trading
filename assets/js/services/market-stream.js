/**
 * The live market feed, and the two events it raises on `document`:
 *
 * - `market:update` — one per WebSocket message, i.e. one per simulation tick, carrying the
 *   tick's payload verbatim. For consumers that must see every tick: a price chart extending
 *   the current bar's high and low, and nothing else.
 * - `market:frame` — the same shape, coalesced: the latest entry per ticker, every event
 *   since the previous frame, the latest macro state and curve, at most every
 *   FRAME_INTERVAL_MS and on an animation frame. For anything that writes to the DOM. The
 *   cost of a live page is then set by this wall-clock cadence, not by how fast the
 *   simulation ticks (SIM_TICK_INTERVAL_US), and several ticks landing inside one frame cost
 *   one write, not several.
 */

let isConnected = false;
let globalMarketSocket = null;
let reconnectTimeout = 1000;
let intentionalClose = false;
let visibilityListenerAdded = false;

/** Minimum gap between two `market:frame` events. Four a second is a ticker tape's pace. */
export const FRAME_INTERVAL_MS = 250;

let pendingFrame = null;
let frameTimer = null;
let frameRaf = null;
let lastFrameAt = 0;

/** Scalar fields carried through as "latest wins". */
const FRAME_SCALARS = ['timestamp', 'tick', 'market_vol', 'economic_cycle', 'council_rate', 'macro', 'bond_curve'];

/** Folds one tick's payload into the frame being assembled. */
function absorbIntoFrame(payload) {
    if (!pendingFrame) {
        pendingFrame = { stocks: new Map(), events: [], district: null };
    }
    FRAME_SCALARS.forEach(key => {
        if (payload[key] !== undefined) pendingFrame[key] = payload[key];
    });
    if (Array.isArray(payload.stocks)) {
        payload.stocks.forEach(entry => {
            if (entry && entry.ticker) pendingFrame.stocks.set(entry.ticker, entry);
        });
    }
    // Events are occurrences, not state: every one since the last frame is delivered.
    if (Array.isArray(payload.events) && payload.events.length > 0) {
        pendingFrame.events.push(...payload.events);
    }
    // A reconstitution announcement is one message on one tick; it must survive coalescing.
    if (payload.district && typeof payload.district === 'object') {
        pendingFrame.district = payload.district;
    }
}

function scheduleFrame() {
    if (frameTimer !== null || frameRaf !== null) return;
    const wait = Math.max(0, FRAME_INTERVAL_MS - (Date.now() - lastFrameAt));
    frameTimer = setTimeout(() => {
        frameTimer = null;
        frameRaf = requestAnimationFrame(dispatchFrame);
    }, wait);
}

function dispatchFrame() {
    frameRaf = null;
    lastFrameAt = Date.now();
    const frame = pendingFrame;
    pendingFrame = null;
    if (!frame) return;

    const detail = { ...frame, stocks: Array.from(frame.stocks.values()) };
    if (detail.district === null) delete detail.district;
    document.dispatchEvent(new CustomEvent('market:frame', { detail }));
}

export function initMarketStream() {
    // ws_ticket() signs a ticket for guests too (uid "guest"), so an empty ticket means the
    // base layout did not run, not an anonymous visitor. The feed is a public broadcast.
    if (!window.WS_TICKET || window.WS_TICKET === "") {
        console.log("No WebSocket ticket on this page: live updates disabled.");
        return;
    }
    
    if (globalMarketSocket) return; // Already initialized

    function connect() {
        intentionalClose = false;
        if (globalMarketSocket) {
            globalMarketSocket.close();
        }
        
        const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        const host = window.location.host;
        globalMarketSocket = new WebSocket(`${protocol}${host}/ws/?ticket=${window.WS_TICKET}`);

        globalMarketSocket.onopen = function() {
            console.log("Connected to live market feed.");
            isConnected = true;
            reconnectTimeout = 1000; // Reset timeout on successful connection
            document.dispatchEvent(new CustomEvent('market:connected'));
        };

        globalMarketSocket.onmessage = function(event) {
            try {
                const payload = JSON.parse(event.data);
                document.dispatchEvent(new CustomEvent('market:update', { detail: payload }));
                absorbIntoFrame(payload);
                scheduleFrame();
            } catch (e) {
                console.error("Failed to parse market update", e);
            }
        };

        globalMarketSocket.onclose = function(event) {
            isConnected = false;
            globalMarketSocket = null;
            document.dispatchEvent(new CustomEvent('market:disconnected'));
            if (!intentionalClose) {
                console.log("WebSocket closed. Reconnecting in " + reconnectTimeout + "ms...");
                setTimeout(connect, reconnectTimeout);
                reconnectTimeout = Math.min(reconnectTimeout * 2, 30000); // Exponential backoff up to 30s
            } else {
                console.log("WebSocket closed intentionally (page hidden).");
            }
        };
    }

    if (!visibilityListenerAdded) {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                if (!globalMarketSocket) {
                    console.log("Page visible, reconnecting WebSocket...");
                    connect();
                }
            } else {
                if (globalMarketSocket) {
                    console.log("Page hidden, closing WebSocket to prevent buffer overflow...");
                    intentionalClose = true;
                    globalMarketSocket.close();
                }
            }
        });
        visibilityListenerAdded = true;
    }

    // Connect if the page is visible right now
    if (document.visibilityState === 'visible') {
        connect();
    }
}
