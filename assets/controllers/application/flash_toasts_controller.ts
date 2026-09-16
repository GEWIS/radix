import { Controller } from '@hotwired/stimulus';

declare global {
    interface Window {
        bootstrap: {
            Toast: {
                getOrCreateInstance(element: Element): { show(): void };
            };
        };
    }
}

/**
 * Shows the flashes the server rendered into the toast container.
 *
 * This ran from a `DOMContentLoaded` handler in the partial itself, which fires once per document. Stimulus connects
 * on every render, so flashes are also shown on a page rendered without a document load.
 *
 * A toast is removed once hidden, and the container is emptied before the page is cached, because a flash belongs to
 * the render that produced it. One left in the markup would be shown again on a later connect, long after the action
 * it reported.
 */
export default class extends Controller {
    static targets = ['container'];

    declare readonly containerTarget: HTMLElement;

    // The toasts still waiting to be shown. A page that stays open receives several rounds of flashes, so an id is
    // dropped as its toast is shown rather than kept until the controller goes away.
    private readonly timers = new Set<number>();

    // The realtime connection appends its own toasts to this container, and those must not be restored either.
    private readonly onBeforeCache = (): void => {
        this.containerTarget.replaceChildren();
    };

    connect(): void {
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.show();
    }

    disconnect(): void {
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        // A pending stagger would otherwise show a toast that is no longer in the document.
        this.timers.forEach((timer) => window.clearTimeout(timer));
        this.timers.clear();
    }

    private show(): void {
        if (undefined === window.bootstrap) {
            return;
        }

        // Staggered so several flashes animate in one after another rather than as one block.
        this.containerTarget.querySelectorAll('.toast:not(.show)').forEach((toast, index) => {
            toast.addEventListener('hidden.bs.toast', (): void => toast.remove());

            const timer = window.setTimeout(() => {
                this.timers.delete(timer);
                window.bootstrap.Toast.getOrCreateInstance(toast).show();
            }, index * 10);

            this.timers.add(timer);
        });
    }
}
