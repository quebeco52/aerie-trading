/**
 * Live stories on the newswire (templates/news/index.html.twig).
 *
 * Each wire event arrives filed by App\Service\Event\NewsDesk: `scope` (district, company or fund), `section` and
 * `headline`, so a live story lands in the same section and weight it will have on the next page load.
 */

import { buildCard } from '../stock/events-feed.js';

/** Rows kept on the page as live stories push older ones off; the server lists the same number. */
const FEED_ROWS = 60;

/** Mirrors NewsDesk::inSection(). */
function inSection(section, evt) {
    if (section === 'all') return true;
    if (section === 'companies') return evt.scope === 'company';
    return evt.section === section;
}

let frameHandler = null;

function init() {
    const feed = document.getElementById('news-feed');
    if (!feed) return;
    const section = feed.dataset.section || 'all';

    frameHandler = (e) => {
        const events = e.detail?.events;
        if (!Array.isArray(events)) return;

        events.forEach(evt => {
            if (!evt.presented || !inSection(section, evt)) return;

            document.getElementById('no-news-msg')?.remove();
            feed.prepend(buildCard(evt.presented, { source: evt.ticker ? { ticker: evt.ticker } : null, headline: evt.headline === true }));
            while (feed.children.length > FEED_ROWS) feed.removeChild(feed.lastChild);
        });
    };
    document.addEventListener('market:frame', frameHandler);

    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:frame', frameHandler);
        frameHandler = null;
    }, { once: true });
}

init();
