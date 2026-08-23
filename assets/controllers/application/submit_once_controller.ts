import { Controller } from '@hotwired/stimulus';

/**
 * Disable a form's submit button once the form is submitted, and replace its label with a spinner.
 *
 * The button is disabled a tick after the event rather than inside it: a form's data is collected after its `submit`
 * handlers have run, so a button disabled inside one is left out of the request along with its name and value.
 *
 * A disabled button is marked `data-locked` for sibling controllers that also set its disabled state, so that they
 * do not enable it again.
 *
 * ```
 * <form data-controller="submit-once" data-action="submit->submit-once#lock"
 *       data-submit-once-label-value="Processing...">
 *     <button type="submit" data-submit-once-target="button">Go to checkout</button>
 * </form>
 * ```
 */
export default class extends Controller {
    static targets = ['button'];
    static values = { label: String };

    declare readonly buttonTargets: HTMLButtonElement[];
    declare readonly labelValue: string;
    declare readonly hasLabelValue: boolean;

    lock(): void {
        window.setTimeout(() => {
            this.buttonTargets.forEach((button) => this.disable(button));
        });
    }

    private disable(button: HTMLButtonElement): void {
        button.dataset.locked = 'true';
        button.disabled = true;

        if (!this.hasLabelValue) {
            return;
        }

        const spinner = document.createElement('span');

        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('aria-hidden', 'true');

        // Not innerHTML: the label is translated text and must not be parsed as markup.
        button.replaceChildren(spinner, document.createTextNode(this.labelValue));
    }
}
