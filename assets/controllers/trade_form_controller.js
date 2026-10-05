import { Controller } from '@hotwired/stimulus';
import { formatCurrency, formatPercent, formatShares } from '../js/utils/formatters.js';

/**
 * The order ticket.
 *
 * The estimate reprices off the market stream: on a live tape a page-load snapshot is stale
 * within a tick, and the figure beside the Buy button has to be the price the order fills at.
 * Submission locks the button, so a slow round-trip plus a second click cannot place two orders.
 */
export default class extends Controller {
    static targets = ['limitGroup', 'limitPrice', 'stopGroup', 'stopPrice', 'quantity', 'estimate', 'estimateLabel', 'duty', 'costsRow', 'costs', 'costsNote', 'submit'];
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
        clearTimeout(this.quoteTimer);
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
            this.estimateLabelTarget.textContent = this.isBuySide ? 'Estimated cost' : 'Estimated proceeds';
        }

        if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(price)) {
            this.estimateTarget.textContent = formatCurrency(null);
            if (this.hasDutyTarget) this.dutyTarget.textContent = formatCurrency(null);
            return;
        }

        // An order that takes the book (market, or a plain stop once it fires) pays the half-spread and its
        // own impact inside the fill price; a limit names its price, so nothing is added to it.
        const takesBook = this.orderType === 'MARKET' || this.orderType === 'STOP';
        const costs = takesBook ? this.costsFor(quantity) : null;
        const crossing = costs ? price * quantity * (costs.spread + costs.impact) : 0;
        this.renderCosts(takesBook, costs, crossing, quantity);

        const consideration = price * quantity + (this.isBuySide ? crossing : -crossing);
        const duty = consideration * this.dutyRateValue;
        if (this.hasDutyTarget) this.dutyTarget.textContent = formatCurrency(duty);
        this.estimateTarget.textContent = formatCurrency(this.isBuySide ? consideration + duty : consideration - duty);
    }

    /**
     * The desk's spread and impact for this size and side, as fractions of the consideration: fetched once
     * per size and side (they scale with the price, so the live tape reprices them), null until it answers.
     */
    costsFor(quantity) {
        const key = `${this.action}:${quantity}`;
        if (this.quotedKey === key) return this.quotedCosts;
        if (this.pendingKey !== key) {
            this.pendingKey = key;
            clearTimeout(this.quoteTimer);
            this.quoteTimer = setTimeout(() => this.fetchCosts(key, quantity), 250);
        }
        return null;
    }

    async fetchCosts(key, quantity) {
        const params = new URLSearchParams({ ticker: this.tickerValue, action: this.action, quantity: String(quantity) });
        try {
            const res = await fetch(`/api/trade/quote?${params}`, { headers: { Accept: 'application/json' } });
            if (!res.ok || this.pendingKey !== key) return;
            this.quotedCosts = await res.json();
            this.quotedKey = key;
            this.render();
        } catch (e) {
            // No quote is a missing figure, not a free trade: the row keeps its dash.
        }
    }

    renderCosts(takesBook, costs, crossing, quantity) {
        if (this.hasCostsRowTarget) this.costsRowTarget.classList.toggle('hidden', !takesBook);
        if (this.hasCostsTarget) this.costsTarget.textContent = costs ? formatCurrency(crossing) : formatCurrency(null);
        if (!this.hasCostsNoteTarget) return;

        let note = '';
        if (costs && Number.isFinite(costs.maximum) && quantity > costs.maximum) {
            note = `Above the ${formatShares(costs.maximum)} shares the desk takes at once.`;
        } else if (costs && costs.participation > 0) {
            note = `${formatPercent(costs.participation, 1)} of a day's volume`;
        }
        this.costsNoteTarget.textContent = note;
        this.costsNoteTarget.classList.toggle('hidden', note === '');
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
