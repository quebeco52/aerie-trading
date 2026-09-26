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
    static targets = ['limitGroup', 'limitPrice', 'stopGroup', 'stopPrice', 'quantity', 'estimate', 'estimateLabel', 'duty', 'submit'];
    static values = {
        ticker: String,
        /** Last traded price, refreshed from the market stream for as long as the page is open. */
        price: Number,
        /** Stamp duty on the consideration, each side of the trade; zero for an instrument that carries none. */
        dutyRate: { type: Number, default: 0 }
    };

    connect() {
        // The coalesced frame (market-stream.js): the ticket only needs the latest quote.
        this.marketUpdateHandler = (event) => this.onMarketUpdate(event);
        document.addEventListener('market:frame', this.marketUpdateHandler);
        // Buying pays the duty on top, selling pays it out of the proceeds, so the side changes the estimate.
        this.actionChangeHandler = (event) => {
            if (event.target?.name === 'action') this.render();
        };
        this.element.addEventListener('change', this.actionChangeHandler);
        this.render();
    }

    disconnect() {
        document.removeEventListener('market:frame', this.marketUpdateHandler);
        this.element.removeEventListener('change', this.actionChangeHandler);
    }

    get action() {
        return this.element.querySelector('input[name="action"]:checked')?.value || 'BUY';
    }

    /** BUY and COVER pay cash out; SELL and SHORT take it in. */
    get isBuySide() {
        return this.action === 'BUY' || this.action === 'COVER';
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

        if (this.hasEstimateLabelTarget) {
            this.estimateLabelTarget.textContent = this.isBuySide ? 'Estimated Cost' : 'Estimated Proceeds';
        }

        if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(price)) {
            this.estimateTarget.textContent = formatCurrency(null);
            if (this.hasDutyTarget) this.dutyTarget.textContent = formatCurrency(null);
            return;
        }

        const consideration = price * quantity;
        const duty = consideration * this.dutyRateValue;
        if (this.hasDutyTarget) this.dutyTarget.textContent = formatCurrency(duty);
        this.estimateTarget.textContent = formatCurrency(this.isBuySide ? consideration + duty : consideration - duty);
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
