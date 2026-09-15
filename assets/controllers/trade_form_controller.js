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
    static targets = ['limitGroup', 'limitPrice', 'quantity', 'estimate', 'submit'];
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
        // A limit order is priced by the trader, so a new quote does not move its estimate.
        if (this.orderType === 'MARKET') this.render();
    }

    /** Shows or hides the limit price field to match the selected order type. */
    onOrderTypeChange() {
        const isLimit = this.orderType === 'LIMIT';
        if (this.hasLimitGroupTarget) {
            this.limitGroupTarget.classList.toggle('hidden', !isLimit);
        }
        this.render();
    }

    get orderType() {
        return this.element.querySelector('input[name="orderType"]:checked')?.value || 'MARKET';
    }

    /** The price this order would transact at: the trader's limit, or the live quote. */
    get effectivePrice() {
        if (this.orderType === 'LIMIT' && this.hasLimitPriceTarget && this.limitPriceTarget.value) {
            const limit = parseFloat(this.limitPriceTarget.value);
            if (Number.isFinite(limit)) return limit;
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
