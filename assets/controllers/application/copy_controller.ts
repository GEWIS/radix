import { Controller } from '@hotwired/stimulus';

/**
 * What gets copied is either handed over through the `text` value or read out of the source target. Both exist
 * because a page does not always display what it offers to copy: a decision's LaTeX form, for instance, is nothing
 * like the sentence shown above the button.
 *
 * The confirmation replaces the label target when there is one, and the button itself otherwise. A button holding an
 * icon needs the label target: assigning to the button's own `textContent` would replace its children with a single
 * text node and take the icon with it, for good.
 */
export default class extends Controller {
    static targets = ['source', 'button', 'label'];
    static values = {
        text: String,
        done: String,
    };

    declare readonly hasSourceTarget: boolean;
    declare readonly sourceTarget: HTMLElement;
    declare readonly hasButtonTarget: boolean;
    declare readonly buttonTarget: HTMLElement;
    declare readonly hasLabelTarget: boolean;
    declare readonly labelTarget: HTMLElement;

    declare readonly textValue: string;
    declare readonly doneValue: string;

    private original = '';
    private restore: number | null = null;

    connect(): void {
        const feedback = this.feedback();

        if (null === feedback) {
            return;
        }

        // Read once, so that clicking again while the button still says "Copied" cannot make that the label it
        // returns to.
        this.original = feedback.textContent ?? '';
    }

    disconnect(): void {
        if (null === this.restore) {
            return;
        }

        clearTimeout(this.restore);
    }

    async copy(event: Event): Promise<void> {
        const text = this.text();

        if ('' === text) {
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            return;
        }

        this.confirm(event.currentTarget as HTMLElement);
    }

    private text(): string {
        if ('' !== this.textValue) {
            return this.textValue.trim();
        }

        return this.hasSourceTarget ? this.sourceTarget.innerText.trim() : '';
    }

    private feedback(): HTMLElement | null {
        if (this.hasLabelTarget) {
            return this.labelTarget;
        }

        return this.hasButtonTarget ? this.buttonTarget : null;
    }

    /**
     * A button may carry its own confirmation, for a list where every row has one; the value on the controller element
     * covers the ordinary case of a single button.
     */
    private confirm(button: HTMLElement): void {
        const feedback = this.feedback();
        const done = button.dataset.copyCopiedLabel ?? this.doneValue;

        if (null === feedback || '' === done) {
            return;
        }

        if (null !== this.restore) {
            clearTimeout(this.restore);
        }

        feedback.textContent = done;
        this.restore = window.setTimeout(() => {
            feedback.textContent = this.original;
            this.restore = null;
        }, 2000);
    }
}
