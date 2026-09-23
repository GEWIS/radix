import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

// These fire before a value changes, so the values read on them are the ones from before the change.
const INTERACTIONS = ['focusin', 'pointerdown', 'drop'];

/**
 * Warns before a form with changes that were not saved is left.
 *
 * Attached to every POST form by `templates/form/bootstrap_floating.html.twig`; a form that renders
 * `data-unsaved-changes="off"` is skipped.
 *
 * A Turbo Drive visit replaces the body without unloading the document and fires no `beforeunload`, so
 * `turbo:before-visit` is listened for as well. Neither fires for a history navigation, which is not covered.
 *
 * The values are compared against the ones read at the first interaction, rather than a flag being set on the first
 * keystroke, so a change that was undone does not count, and neither does a value an editor writes into a field
 * before the form is used.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller<HTMLFormElement> {
    static values = { message: String };

    declare readonly messageValue: string;

    private baseline: string | null = null;
    private armed = false;

    private readonly _onInteraction = (): void => {
        this.baseline ??= this.snapshot();
    };

    private readonly _onChange = (): void => {
        if (null === this.baseline) {
            return;
        }

        this.arm(this.snapshot() !== this.baseline);
    };

    private readonly _onSubmit = (): void => {
        this.arm(false);
    };

    private readonly _onUnload = (event: BeforeUnloadEvent): void => {
        // Not every browser opens its dialog on the cancelled event alone.
        event.preventDefault();
        event.returnValue = '';
    };

    private readonly _onVisit = (event: Event): void => {
        if (!this.armed) {
            return;
        }

        const url = (event as CustomEvent<{ url: string }>).detail.url;
        event.preventDefault();

        if (!window.confirm(this.messageValue)) {
            return;
        }

        this.arm(false);
        visit(url);
    };

    connect(): void {
        // A form inside a live component is submitted by an action rather than by a navigation, and a
        // re-render changes its fields.
        if (null !== this.element.closest('[data-live-name-value]')) {
            return;
        }

        INTERACTIONS.forEach((name: string): void => {
            this.element.addEventListener(name, this._onInteraction);
        });
        this.element.addEventListener('input', this._onChange);
        this.element.addEventListener('change', this._onChange);
        this.element.addEventListener('submit', this._onSubmit);
        this.element.addEventListener('turbo:submit-start', this._onSubmit);
        document.addEventListener('turbo:before-visit', this._onVisit);
    }

    disconnect(): void {
        INTERACTIONS.forEach((name: string): void => {
            this.element.removeEventListener(name, this._onInteraction);
        });
        this.element.removeEventListener('input', this._onChange);
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('submit', this._onSubmit);
        this.element.removeEventListener('turbo:submit-start', this._onSubmit);
        document.removeEventListener('turbo:before-visit', this._onVisit);

        this.arm(false);
    }

    /**
     * Registered only while there is something to warn about, because a listener that is always registered can
     * keep the page out of the back/forward cache.
     */
    private arm(armed: boolean): void {
        if (armed === this.armed) {
            return;
        }

        this.armed = armed;
        if (armed) {
            window.addEventListener('beforeunload', this._onUnload);

            return;
        }

        window.removeEventListener('beforeunload', this._onUnload);
    }

    private snapshot(): string {
        const values: string[] = [];
        new FormData(this.element).forEach((value: FormDataEntryValue, name: string): void => {
            values.push(
                value instanceof File
                    ? `${name}=${value.name}:${value.size}:${value.lastModified}`
                    : `${name}=${value}`,
            );
        });

        return values.join('\n');
    }
}
