/**
 * What a request the browser made for itself does when the sudo window has run out underneath it.
 *
 * The server returns 401 rather than a redirect, because `fetch` follows a redirect transparently and returns the
 * confirmation page with a 200 to a caller expecting a result. That is why an upload reported success for a file
 * that was never stored.
 *
 * Confirming is a page rather than a request, so the only option is to navigate to it. The request body is lost
 * either way; navigating prevents the caller from reporting a result it did not get.
 */
export async function confirmSudoIfRequired(response: Response): Promise<boolean> {
    if (401 !== response.status) {
        return false;
    }

    const payload = (await response.clone().json().catch(() => null)) as {
        error?: string;
        confirmUrl?: string;
    } | null;
    if ('sudo_required' !== payload?.error || undefined === payload.confirmUrl) {
        return false;
    }

    // Not a Turbo visit: the grant has expired, and the response must replace the document rather than be rendered
    // into the one already open.
    window.location.assign(payload.confirmUrl);

    return true;
}
