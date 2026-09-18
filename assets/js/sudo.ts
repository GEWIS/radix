/**
 * What a request the browser made for itself does when the sudo window has run out underneath it.
 *
 * The server returns 401 rather than a redirect, because `fetch` follows a redirect transparently and returns the
 * confirmation page with a 200 to a caller expecting a result. That is why an upload reported success for a file
 * that was never stored.
 *
 * Confirming is a page rather than a request, so the only option is to navigate to it. The address the server
 * returns states both the page to come back to and the write that was refused, so what was being sent is not lost by
 * navigating away from it.
 */

interface SudoRequired {
    error?: string;
    confirmUrl?: string;
}

/**
 * Where to confirm, for a response that is the server stating the window has run out, and null for anything else.
 *
 * Takes the status and the body rather than a `Response`, because the bulk uploader reports progress per file and so
 * sends its requests with `XMLHttpRequest`. Both callers have to agree on what the 401 means, which is why the shape
 * is read in one place.
 */
export function sudoConfirmUrl(
    status: number,
    body: string,
): string | null {
    if (401 !== status) {
        return null;
    }

    let payload: SudoRequired | null = null;
    try {
        payload = JSON.parse(body) as SudoRequired;
    } catch {
        return null;
    }

    if ('sudo_required' !== payload?.error || undefined === payload.confirmUrl) {
        return null;
    }

    return payload.confirmUrl;
}

/**
 * Navigates to the confirmation page when the response is the one the server returns for a lapsed grant, and states
 * whether it did so, for a caller that must stop rather than report a failure.
 */
export async function confirmSudoIfRequired(response: Response): Promise<boolean> {
    if (401 !== response.status) {
        return false;
    }

    const confirmUrl = sudoConfirmUrl(
        response.status,
        await response.clone().text().catch(() => ''),
    );
    if (null === confirmUrl) {
        return false;
    }

    // Not a Turbo visit: the grant has expired, and the response must replace the document rather than be rendered
    // into the one already open.
    window.location.assign(confirmUrl);

    return true;
}
