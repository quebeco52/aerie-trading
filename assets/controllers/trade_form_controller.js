import { Controller } from '@hotwired/stimulus';
import { formatCurrency } from '../js/utils/formatters.js';

/**
 * The order ticket.
 *
 * The estimate reprices off the market stream: on a live tape a page-load snapshot is stale
 * within a tick, and the figure beside the Buy button has to be the price the order fills at.
 * Submission locks the button, so a slow round-trip plus a second click cannot place two orders.
 */
export default class extends Controller {
    static targets = ['limitGroup', 'limitPrice', 'stopGroup', 'stopPrice', 'quantity', 'estimate', 'submit'];
    static values = {
        ticker: String,
        /** Last traded price, refreshed from the market stream for as long as the page is open. */
        price: Number
    };

    connect() {
        // The coalesced frame (market-stream.js): the ticket only needs the latest quote.
        this.marketUpdateHandler = (event) => this.onMarketUpdate(event);
        document.addEventListener('market:frame', this.marketUpdateHandler);
        this.render();
    }

    disconnect() {
        document.removeEventListener('market:frame', this.marketUpdateHandler);
    }

    onMarketUpdate(event) {
        const stocks = event.detail?.stocks;
        if (!Array.isArray(stocks)) return;

        const quote = stocks.find(s => s.ticker === this.tickerValue);
        if (!quote) return;

        const price = parseFloat(quote.price);
        if (!Number.isFinite(price)) return;

        this.priceValue = price;
        // A limit is priced by the trader, so a new quote does not move its estimate. A plain stop has
        // no price of its own — it takes what the book gives it — so its estimate DOES follow the quote.
        if (this.orderType === 'MARKET' || this.orderType === 'STOP') this.render();
    }

    /** Shows the price fields the selected order type actually uses. */
    onOrderTypeChange() {
        const type = this.orderType;
        if (this.hasLimitGroupTarget) {
            this.limitGroupTarget.classList.toggle('hidden', type !== 'LIMIT' && type !== 'STOP_LIMIT');
        }
        if (this.hasStopGroupTarget) {
            this.stopGroupTarget.classList.toggle('hidden', type !== 'STOP' && type !== 'STOP_LIMIT');
        }
        this.render();
    }

    get orderType() {
        return this.element.querySelector('input[name="orderType"]:checked')?.value || 'MARKET';
    }

    /**
     * The price this order would transact at: the trader's limit, the trigger a plain stop would fire at,
     * or the live quote.
     *
     * A plain stop is estimated at its trigger, which is the closest honest figure available before it
     * fires — the actual fill can be worse, and on a fast tape usually is.
     */
    get effectivePrice() {
        const type = this.orderType;

        if ((type === 'LIMIT' || type === 'STOP_LIMIT') && this.hasLimitPriceTarget && this.limitPriceTarget.value) {
            const limit = parseFloat(this.limitPriceTarget.value);
            if (Number.isFinite(limit)) return limit;
        }

        if (type === 'STOP' && this.hasStopPriceTarget && this.stopPriceTarget.value) {
            const stop = parseFloat(this.stopPriceTarget.value);
            if (Number.isFinite(stop)) return stop;
        }

        return this.priceValue;
    }

    render() {
        if (!this.hasEstimateTarget) return;

        const quantity = parseInt(this.hasQuantityTarget ? this.quantityTarget.value : '0', 10);
        const price = this.effectivePrice;

        if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(price)) {
            this.estimateTarget.textContent = formatCurrency(null);
            return;
        }
        this.estimateTarget.textContent = formatCurrency(price * quantity);
    }

    /**
     * Locks the ticket once it has been sent. Turbo drives this form, so `turbo:submit-end`
     * releases the button if the server answers with a validation error rather than a redirect.
     */
    onSubmit() {
        if (!this.hasSubmitTarget) return;

        this.submitTarget.disabled = true;
        this.submitTarget.classList.add('opacity-60', 'cursor-not-allowed');
        this.originalSubmitText = this.submitTarget.textContent;
        this.submitTarget.textContent = 'Submitting…';

        this.releaseHandler = () => this.releaseSubmit();
        document.addEventListener('turbo:submit-end', this.releaseHandler, { once: true });
    }

    releaseSubmit() {
        if (!this.hasSubmitTarget) return;
        this.submitTarget.disabled = false;
        this.submitTarget.classList.remove('opacity-60', 'cursor-not-allowed');
        if (this.originalSubmitText) this.submitTarget.textContent = this.originalSubmitText;
    }
}
