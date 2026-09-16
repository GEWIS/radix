/*
 * Matomo counts a page view when its tracker loads, which happens once per document. Turbo Drive renders most pages
 * without a document load, so every page after the first would be uncounted.
 *
 * Imported by the entrypoint rather than included as a body script. The entrypoint is in the head, which Turbo's
 * merge leaves in place, so the module is evaluated once; a body script would be evaluated on every render and
 * register another listener each time.
 *
 * Kept out of the head's own Matomo block as well. The head merge only leaves that block in place because its output
 * is byte-identical on every page, and a per-page value in it would both re-run it and throw on the `const` it
 * declares.
 */
let previous = window.location.href;
let counted = false;

document.addEventListener('turbo:load', () => {
    // The head block already counted the page the document loaded with, and `turbo:load` also fires for it.
    if (!counted) {
        counted = true;

        return;
    }

    const paq = window._paq = window._paq || [];

    paq.push(['setReferrerUrl', previous]);
    paq.push(['setCustomUrl', window.location.href]);
    paq.push(['setDocumentTitle', document.title]);
    paq.push(['trackPageView']);
    // Re-scanned per view because the links it binds to are in the body Turbo has just replaced.
    paq.push(['enableLinkTracking']);

    previous = window.location.href;
});
