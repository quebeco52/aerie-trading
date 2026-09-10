import './stimulus_bootstrap.js';
import './styles/app.css';

import { initMarketStream } from './js/services/market-stream.js';

// `turbo:load` covers the first load as well as every navigation, so this is the only hook
// needed. Turbo Core is eagerly fetched (assets/controllers.json) and cannot miss the event.
document.addEventListener('turbo:load', initMarketStream);
