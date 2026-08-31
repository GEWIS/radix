import { Controller } from '@hotwired/stimulus';

const LEADER_LOCK = 'gewis:realtime:leader';
const CHANNEL_NAME = 'gewis:realtime';
const RELOAD_KEY = 'gewis:realtime:reloaded';
const RELOAD_THROTTLE_MS = 60000;
const RECOVER_BASE_MS = 1000;
const RECOVER_MAX_MS = 60000;

declare global {
    interface Window {
        bootstrap: {
            Toast: {
                getOrCreateInstance(element: Element): { show(): void };
            };
        };
    }
}

interface Envelope {
    type: string;
    [key: string]: unknown;
}

interface LocalisedText {
    en?: string;
    nl?: string;
}

/**
 * The application's Server-Sent Events connection, mounted once per page by the base layout.
 *
 * One connection per browser, not per tab: whichever tab wins an exclusive Web Lock opens it and passes what arrives
 * to the others over a BroadcastChannel. Releasing the lock is what hands the connection on, which the browser does
 * for a tab that is closed. Without either API every tab keeps its own connection.
 *
 * Messages are routed on their `type`: system commands act on the browser (sign out, reload) and a toast is rendered
 * for the user. Any other type is re-emitted as a `gewis:realtime:<type>` DOM event so a feature can react without
 * opening a second connection.
 *
 *   <div data-controller="notifications"
 *        data-notifications-hub-url-value="{{ mercure(topics, { subscribe: topics }) }}"
 *        data-notifications-refresh-url-value="{{ path('app_realtime_grant') }}"
 *        data-notifications-locale-value="{{ app.request.locale }}"></div>
 */
export default class extends Controller<HTMLElement> {
    static values = {
        hubUrl: String,
        refreshUrl: String,
        locale: String,
    };

    declare readonly hubUrlValue: string;
    declare readonly refreshUrlValue: string;
    declare readonly localeValue: string;

    private source: EventSource | null = null;

    private channel: BroadcastChannel | null = null;

    /** Resolving this releases the lock, and with it the connection. */
    private standDown: (() => void) | null = null;

    private election: AbortController | null = null;

    /** Bumped by stop(), so work that was already in flight can tell that this tab has since let go. */
    private generation = 0;

    private recoverTimer: number | null = null;

    private recoverAttempts = 0;

    connect(): void {
        window.addEventListener('pagehide', this.onPageHide);
        window.addEventListener('pageshow', this.onPageShow);

        this.start();
    }

    disconnect(): void {
        window.removeEventListener('pagehide', this.onPageHide);
        window.removeEventListener('pageshow', this.onPageShow);

        this.stop();
        this.channel?.close();
        this.channel = null;
    }

    /** A tab frozen into the back/forward cache would sit on the lock while unable to read from the connection. */
    private readonly onPageHide = (): void => {
        this.stop();
    };

    /** Restored from that cache rather than loaded, so `connect()` does not run again. */
    private readonly onPageShow = (event: PageTransitionEvent): void => {
        if (!event.persisted) {
            return;
        }

        this.start();
    };

    private start(): void {
        if (
            '' === this.hubUrlValue
            || null !== this.source
            || null !== this.standDown
            || null !== this.election
        ) {
            return;
        }

        // Both or neither: without the lock every tab would post its own copy of every message to the channel.
        if (
            !('locks' in navigator)
            || 'undefined' === typeof BroadcastChannel
        ) {
            this.open();

            return;
        }

        this.channel ??= this.openChannel();

        void this.lead();
    }

    private stop(): void {
        this.generation += 1;

        if (null !== this.recoverTimer) {
            window.clearTimeout(this.recoverTimer);
            this.recoverTimer = null;
        }

        this.election?.abort();
        this.election = null;

        const standDown = this.standDown;
        this.standDown = null;
        standDown?.();

        this.closeSource();
    }

    private async lead(): Promise<void> {
        const election = new AbortController();
        this.election = election;

        try {
            await navigator.locks.request(
                LEADER_LOCK,
                {
                    mode: 'exclusive',
                    signal: election.signal,
                },
                async (): Promise<void> => {
                    this.election = null;
                    this.open();

                    await new Promise<void>((resolve): void => {
                        this.standDown = resolve;
                    });
                },
            );
        } catch {
            // The queue place was abandoned before it came up, so nothing was opened.
        }
    }

    private openChannel(): BroadcastChannel {
        const channel = new BroadcastChannel(CHANNEL_NAME);
        // A channel does not deliver to whoever posted, so nothing is handled twice.
        channel.onmessage = (event: MessageEvent): void => {
            this.handle(event.data as Envelope);
        };

        return channel;
    }

    private open(): void {
        this.source = new EventSource(this.hubUrlValue, { withCredentials: true });
        this.source.onmessage = (event: MessageEvent): void => {
            this.onMessage(event);
        };
        this.source.onerror = (): void => {
            this.onError();
        };
    }

    private closeSource(): void {
        this.source?.close();
        this.source = null;
    }

    private onMessage(event: MessageEvent): void {
        this.recoverAttempts = 0;

        let data: Envelope;
        try {
            data = JSON.parse(event.data) as Envelope;
        } catch {
            return;
        }

        // Received on behalf of every tab, so it goes to the rest before it is acted on here.
        this.channel?.postMessage(data);
        this.handle(data);
    }

    private handle(data: Envelope): void {
        switch (data.type) {
            case 'session.invalidate':
                this.stop();
                window.location.assign('string' === typeof data.redirect ? data.redirect : window.location.href);

                return;
            case 'force_reload':
                window.location.reload();

                return;
            case 'toast':
                // One per window, so it needs no agreement between the tabs. Focus would be one per browser, but a
                // window sitting behind another has none and would be told nothing at all.
                if ('visible' === document.visibilityState) {
                    this.renderToast(data);
                }

                // A toast that came from a persisted notification also belongs in the notification centre, which
                // otherwise would not catch up until the next page load.
                if (undefined !== data.notificationId) {
                    document.dispatchEvent(new CustomEvent('gewis:notification'));
                }

                return;
            default:
                document.dispatchEvent(new CustomEvent(`gewis:realtime:${data.type}`, { detail: data }));
        }
    }

    private onError(): void {
        // CONNECTING means the browser is retrying on its own. CLOSED means it gave up, which for us almost always
        // means the authorization cookie expired.
        if (this.source?.readyState !== EventSource.CLOSED) {
            return;
        }

        this.scheduleRecovery();
    }

    /**
     * A connection that closes the moment it is opened would otherwise be reopened every round trip, for as long as
     * the hub is down. Backing off holds that to something the hub can survive being asked.
     */
    private scheduleRecovery(): void {
        if (null !== this.recoverTimer) {
            return;
        }

        const delay = 0 === this.recoverAttempts
            ? 0
            : Math.min(RECOVER_MAX_MS, RECOVER_BASE_MS * 2 ** (this.recoverAttempts - 1));
        this.recoverAttempts += 1;

        this.recoverTimer = window.setTimeout((): void => {
            this.recoverTimer = null;
            void this.recover();
        }, delay);
    }

    private async recover(): Promise<void> {
        const generation = this.generation;
        const refreshed = await this.refresh();

        // stop() ran while that was in flight, so the connection is another tab's to reopen now, not this one's.
        if (generation !== this.generation) {
            return;
        }

        if (refreshed) {
            this.closeSource();
            this.open();

            return;
        }

        this.reload();
    }

    /** Anything but the bare 204 is the sign-in page, which means the session was ended underneath us. */
    private async refresh(): Promise<boolean> {
        if ('' === this.refreshUrlValue) {
            return false;
        }

        try {
            const response = await fetch(this.refreshUrlValue, { credentials: 'same-origin' });

            return 204 === response.status;
        } catch {
            // Offline, or the application is not answering.
            return false;
        }
    }

    /** Only the tabs left on their own connection can reach this together, and the mark is what spares them. */
    private reload(): void {
        try {
            const last = Number(localStorage.getItem(RELOAD_KEY) ?? '0');
            if (Date.now() - last < RELOAD_THROTTLE_MS) {
                return;
            }

            localStorage.setItem(RELOAD_KEY, String(Date.now()));
        } catch {
            // Nothing to read and nowhere to write, so this tab answers for itself.
        }

        window.location.reload();
    }

    private renderToast(data: Envelope): void {
        const container = document.querySelector('#flash-toast-container');
        const template = document.querySelector<HTMLTemplateElement>('#realtime-toast-template');
        if (null === container || null === template || undefined === window.bootstrap) {
            return;
        }

        const text = this.localise(data.message as LocalisedText);
        if ('' === text) {
            return;
        }

        const toast = template.content.firstElementChild?.cloneNode(true);
        if (!(toast instanceof HTMLElement)) {
            return;
        }

        // Only a genuine warning/danger/success level overrides the template's GEWIS red, keeping ordinary notifications
        // on-brand rather than Bootstrap's info blue.
        const level = 'string' === typeof data.level ? data.level : 'info';
        const indicator = toast.querySelector('.toast-indicator');
        if (indicator instanceof HTMLElement && 'info' !== level) {
            indicator.classList.add(`bg-${level}`);
        }

        const title = toast.querySelector('.toast-title');
        if (title instanceof HTMLElement) {
            const heading = data.title === undefined ? '' : this.localise(data.title as LocalisedText);
            title.textContent = '' !== heading ? heading : 'GEWIS';
        }

        const body = toast.querySelector('.toast-body');
        if (body instanceof HTMLElement) {
            body.textContent = text;
            this.appendLink(body, data);
        }

        container.append(toast);
        window.bootstrap.Toast.getOrCreateInstance(toast).show();
        toast.addEventListener('hidden.bs.toast', (): void => toast.remove());
    }

    private appendLink(body: HTMLElement, data: Envelope): void {
        const link = data.link as { href?: LocalisedText; label?: LocalisedText } | undefined;
        if (undefined === link || undefined === link.href || undefined === link.label) {
            return;
        }

        // The href comes in per language for the same reason the label does: point the reader at the page in their own
        // language rather than whichever one happened to be active when the notification was published.
        const href = this.localise(link.href);
        const label = this.localise(link.label);
        if ('' === href || '' === label) {
            return;
        }

        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.className = 'd-block mt-1';
        anchor.textContent = label;
        body.append(anchor);
    }

    private localise(text: LocalisedText): string {
        return text[this.localeValue as keyof LocalisedText] ?? text.en ?? '';
    }
}
