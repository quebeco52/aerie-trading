let isConnected = false;
let globalMarketSocket = null;
let reconnectTimeout = 1000;

export function initMarketStream() {
    if (!window.WS_TICKET || window.WS_TICKET === "") {
        console.log("Guest mode: Live WebSocket updates disabled.");
        return;
    }
    
    if (globalMarketSocket) return; // Already initialized

    function connect() {
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
            } catch (e) {
                console.error("Failed to parse market update", e);
            }
        };

        globalMarketSocket.onclose = function(event) {
            console.log("WebSocket closed. Reconnecting in " + reconnectTimeout + "ms...");
            isConnected = false;
            globalMarketSocket = null;
            document.dispatchEvent(new CustomEvent('market:disconnected'));
            setTimeout(connect, reconnectTimeout);
            reconnectTimeout = Math.min(reconnectTimeout * 2, 30000); // Exponential backoff up to 30s
        };
    }

    connect();
}
