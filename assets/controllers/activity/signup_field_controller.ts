import { Controller } from '@hotwired/stimulus';

/**
 * ```
 * <div data-controller="signup-field">
 *     <select data-signup-field-target="type" data-action="change->signup-field#typeChanged">...</select>
 *     <div data-signup-field-target="number">...min/max...</div>
 *     <div data-signup-field-target="choice">...options collection, each with
 *         <input type="checkbox" data-signup-field-target="defaultOption"
 *                data-action="change->signup-field#defaultChanged">...</div>
 * </div>
 * ```
 *
 * A field tagged `required` is one its block exists to ask for, so it is required exactly while that block is shown;
 * the server holds a question to the same rule (see SignupFieldType::validateBounds).
 */
export default class extends Controller {
    static targets = ['type', 'number', 'choice', 'defaultOption', 'required'];

    declare readonly typeTarget: HTMLSelectElement;
    declare readonly hasNumberTarget: boolean;
    declare readonly numberTarget: HTMLElement;
    declare readonly hasChoiceTarget: boolean;
    declare readonly choiceTarget: HTMLElement;
    declare readonly defaultOptionTargets: HTMLInputElement[];
    declare readonly requiredTargets: (HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement)[];

    connect(): void {
        // Only set visibility on connect (an existing field keeps its saved options); seeding/clearing is user-driven.
        this.apply();
    }

    typeChanged(): void {
        if ('choice' === this.typeTarget.value) {
            if (0 === this.optionEntries().length) {
                this.addOption();
            }
        } else {
            this.clearOptions();
        }

        this.apply();
    }

    apply(): void {
        const type = this.typeTarget.value;

        if (this.hasNumberTarget) {
            this.numberTarget.hidden = 'number' !== type;
        }

        if (this.hasChoiceTarget) {
            this.choiceTarget.hidden = 'choice' !== type;
        }

        this.markRequired();
    }

    // Only the label is marked, with the same asterisk every other required field carries: a field that is not asked
    // for is still submitted (hidden, not disabled) and is cleared server-side, so the browser must not refuse it.
    markRequired(): void {
        this.requiredTargets.forEach((field) => {
            const asked = null === field.closest('[hidden]');
            Array.from(field.labels ?? []).forEach((label) => { label.classList.toggle('required', asked); });
        });
    }

    /**
     * The "default" checkboxes act as a radio group across this field's options: checking one clears the rest.
     */
    defaultChanged(event: Event): void {
        const changed = event.target;
        if (
            !(changed instanceof HTMLInputElement)
            || !changed.checked
        ) {
            return;
        }

        this.defaultOptionTargets.forEach((checkbox) => {
            if (checkbox === changed) {
                return;
            }

            checkbox.checked = false;
        });
    }

    optionEntries(): HTMLElement[] {
        if (!this.hasChoiceTarget) {
            return [];
        }

        return Array.from(this.choiceTarget.querySelectorAll<HTMLElement>('[data-form-collection-target="entry"]'));
    }

    addOption(): void {
        // Reuse the options' own form-collection "add" button so the prototype/index logic stays in one place.
        const addButton = this.choiceTarget.querySelector<HTMLButtonElement>('[data-action~="form-collection#add"]');
        if (null !== addButton) {
            addButton.click();
        }
    }

    clearOptions(): void {
        this.optionEntries().forEach((entry) => {
            entry.remove();
        });
    }
}
