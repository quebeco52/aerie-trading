import './stimulus_bootstrap.js';
import './styles/app.css';

import { onPageLoad } from './js/utils/page-init.js';
import { initMarketStream } from './js/services/market-stream.js';

// Turbo Core is eagerly fetched (assets/controllers.json) and this entrypoint is preloaded in
// the head, so it is evaluated before the first `turbo:load` and cannot miss it. Importing
// page-init here is what makes that true for the page modules as well: its flag has to start
// tracking from the first full load, before any page module is fetched mid-navigation.
onPageLoad(initMarketStream);
