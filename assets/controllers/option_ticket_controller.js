import { Controller } from '@hotwired/stimulus';
import { formatCurrency } from '../js/utils/formatters.js';

/** Shares one contract is written on. Mirrors FinancialConstants::OPTION_CONTRACT_MULTIPLIER. */
const CONTRACT_MULTIPLIER = 100;

/**
 * The option ticket.
 *
 * Opened from a mark in the chain, because that is how a chain is actually traded: a strike is a cell,
 * not a page. The contract being traded is carried on the button rather than re-derived, so the ticket
 * cannot end up pointing at a different strike from the one that was clicked.
 *
 * Which sides are offered depends on the position the account already has, and the rule is the desk's
 * own: a long is closed by SELL and a short by COVER, and neither BUY nor WRITE may move a position
 * through zero. Enforcing it here means the server's refusal is a backstop rather than the first time a
 * trader learns that overselling a long would have written them a naked contract.
 */
export default class extends Controller {
    static targets = ['modal', 'symbol', 'quantity', 'estimate', 'submit', 'actionGroup', 'hint'];

    connect() {
        this.escapeHandler = (event) => {
            if (event.key === 'Escape' && !this.modalTarget.classList.contains('hidden')) this.close();
        };
        document.addEventListener('keydown', this.escapeHandler);
    }

    disconnect() {
        document.removeEventListener('keydown', this.escapeHandler);
    }

    /** Loads the ticket from the clicked mark and shows it. */
    open(event) {
        const { symbol, mark, position } = event.params;

        this.symbol = symbol;
        this.mark = Number.parseFloat(mark);
        this.position = Number.parseInt(position, 10) || 0;

        this.symbolTarget.textContent = symbol;
        this.element.querySelector('input[name="ticker"]').value = symbol;
        this.quantityTarget.value = 1;

        this.renderActions();
        this.render();

        this.modalTarget.classList.remove('hidden');
        this.modalTarget.classList.add('flex');
        this.quantityTarget.focus();
    }

    close() {
        this.modalTarget.classList.add('hidden');
        this.modalTarget.classList.remove('flex');
    }

    backdropClick(event) {
        if (event.target === this.modalTarget) this.close();
    }

    /**
     * Enables only the sides this position can legally trade, and says why the others are gone.
     *
     * A position is never moved through zero in one order: opening the other side is a separate decision
     * with a different collateral consequence, and on the short side an unbounded one.
     */
    renderActions() {
        const allowed = this.position > 0
            ? ['BUY', 'SELL']
            : (this.position < 0 ? ['WRITE', 'COVER'] : ['BUY', 'WRITE']);

        let firstEnabled = null;

        this.actionGroupTarget.querySelectorAll('input[name="action"]').forEach((input) => {
            const enabled = allowed.includes(input.value);
            const label = input.closest('label');

            input.disabled = !enabled;
            input.checked = false;
            if (label) label.classList.toggle('hidden', !enabled);
            if (enabled && firstEnabled === null) firstEnabled = input;
        });

        if (firstEnabled) firstEnabled.checked = true;

        if (this.position > 0) {
            this.hintTarget.textContent = `You are long ${this.position} contract${this.position === 1 ? '' : 's'}. Sell to close.`;
        } else if (this.position < 0) {
            const written = Math.abs(this.position);
            this.hintTarget.textContent = `You have written ${written} contract${written === 1 ? '' : 's'}. Cover to close.`;
        } else {
            this.hintTarget.textContent = 'Writing a contract requires margin and can lose more than the premium it pays.';
        }
    }

    /** Premium at the mark. The fill crosses the spread and reprices live, so this is an estimate. */
    render() {
        const contracts = Number.parseInt(this.quantityTarget.value, 10);

        if (!Number.isFinite(contracts) || contracts <= 0 || !Number.isFinite(this.mark)) {
            this.estimateTarget.textContent = formatCurrency(null);
            return;
        }

        this.estimateTarget.textContent = formatCurrency(this.mark * CONTRACT_MULTIPLIER * contracts);
    }

    /** Locks the ticket once sent, released by Turbo if the server answers with an error. */
    onSubmit() {
        this.submitTarget.disabled = true;
        this.submitTarget.classList.add('opacity-60', 'cursor-not-allowed');
        this.submitTarget.textContent = 'Submitting…';

        document.addEventListener('turbo:submit-end', () => {
            this.submitTarget.disabled = false;
            this.submitTarget.classList.remove('opacity-60', 'cursor-not-allowed');
            this.submitTarget.textContent = 'Send Order';
        }, { once: true });
    }
}
