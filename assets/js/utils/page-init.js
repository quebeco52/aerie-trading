/**
 * Runs a page module's entry point on `turbo:load`, and immediately if that event has already
 * gone by for the page on screen.
 *
 * Page modules are pulled in by an inline `import 'pages/x'` at the end of the body. On a full
 * page load the browser blocks the document on that import, so the module evaluates before
 * `turbo:load` is dispatched and a plain listener is enough. On a Turbo navigation it does not:
 * Turbo swaps the body, the re-inserted inline script only *starts* the module's network fetch,
 * and `turbo:load` fires in that same task. The first visit to a page type therefore evaluates
 * its module after its own load event has passed, leaving that visit with no charts and a frozen
 * tape until a manual reload — every later visit works, because the module is already registered.
 */

let pageLoaded = false;

// Registered while `app.js` is evaluated, i.e. on the first full page load, so the flag is
// already accurate by the time any page module is fetched mid-navigation.
document.addEventListener('turbo:load', () => {
    pageLoaded = true;
});

export function onPageLoad(init) {
    document.addEventListener('turbo:load', init);
    if (pageLoaded) init();
}
