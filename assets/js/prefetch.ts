/**
 * A prefetched page whose response was a redirect is fetched again when the link is clicked.
 *
 * The server does not store a prefetched address as the page to return to after signing in, because hovering a
 * link is not a visit. The click is the visit, so when the prefetch was redirected to the login page the click has
 * to be sent to the server instead of rendering the prefetched response.
 */

interface PrefetchedRequest {
    response?: Promise<Response>;
}

interface BeforeFetchRequestDetail {
    fetchRequest?: PrefetchedRequest;
    resume: () => void;
}

// Registered without capture so that it runs after Turbo's own listener, which substitutes the prefetched request.
document.addEventListener('turbo:before-fetch-request', (event: Event): void => {
    const detail = (event as CustomEvent<BeforeFetchRequestDetail>).detail;
    const prefetched = detail.fetchRequest;
    if (undefined === prefetched) {
        return;
    }

    event.preventDefault();
    Promise.resolve(prefetched.response)
        .then((response: Response | undefined): void => {
            if (undefined === response || response.redirected) {
                delete detail.fetchRequest;
            }
        })
        .catch((): void => {
            delete detail.fetchRequest;
        })
        .finally((): void => {
            detail.resume();
        });
});
