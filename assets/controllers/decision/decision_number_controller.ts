import { Controller } from '@hotwired/stimulus';

/**
 * Opening the page that records a new decision. Nothing is submitted: the point and the decision number are part of
 * the decision's address, so they go into the URL. The meeting already knows which number is next for each of its
 * points, which saves looking it up in the list above.
 */
export default class extends Controller<HTMLFormElement> {
    static targets = ['point', 'number'];
    static values = {
        url: String,
        next: Object,
    };

    declare readonly pointTarget: HTMLInputElement;
    declare readonly numberTarget: HTMLInputElement;

    declare readonly urlValue: string;
    declare readonly nextValue: Record<string, number>;

    suggest(): void {
        const next = this.nextValue[this.pointTarget.value];

        this.numberTarget.value = undefined === next ? '' : String(next);
    }

    open(event: Event): void {
        event.preventDefault();

        if (!this.element.reportValidity()) {
            return;
        }

        // The route ends in `points/{point}/decisions/{decision}`, and both were generated as a zero, so the tail
        // is rewritten as a whole rather than by counting segments back from the end -- `decisions` sits between the
        // two numbers, so the last two segments are not the two that have to change.
        const url = new URL(this.urlValue, window.location.origin);
        const point = encodeURIComponent(this.pointTarget.value);
        const number = encodeURIComponent(this.numberTarget.value);

        url.pathname = url.pathname.replace(
            /\/points\/\d+\/decisions\/\d+$/,
            `/points/${point}/decisions/${number}`,
        );

        window.location.assign(url.toString());
    }
}
