import { Controller } from '@hotwired/stimulus';

type LocalisedField = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

/**
 * A disabled variant is not submitted, so an unchecked language keeps whatever it already had (empty for a new item,
 * the existing translation for an edit).
 *
 * The markup tags elements generically so this controller needs no per-domain knowledge:
 *
 *   - the checkboxes:  data-localised-fields-target="dutchToggle" | "englishToggle"
 *                      data-action="localised-fields#apply"
 *   - the inputs:      data-localised-fields-target="dutch" | "english"
 *
 * Where the checkboxes are not on the page — a later step of a form flow answers them on an earlier one — the answer
 * is carried as data-localised-fields-dutch-value / -english-value instead. With neither, every language is on.
 */
export default class extends Controller {
    static targets = ['dutchToggle', 'englishToggle', 'dutch', 'english'];
    static values = { dutch: Boolean, english: Boolean };

    declare readonly hasDutchToggleTarget: boolean;
    declare readonly dutchToggleTarget: HTMLInputElement;
    declare readonly hasEnglishToggleTarget: boolean;
    declare readonly englishToggleTarget: HTMLInputElement;
    declare readonly dutchTargets: LocalisedField[];
    declare readonly englishTargets: LocalisedField[];
    declare readonly hasDutchValue: boolean;
    declare readonly dutchValue: boolean;
    declare readonly hasEnglishValue: boolean;
    declare readonly englishValue: boolean;

    connect(): void {
        this.apply();
    }

    apply(): void {
        this.dutchTargets.forEach((field) => { this.enable(field, this.dutchEnabled()); });
        this.englishTargets.forEach((field) => { this.enable(field, this.englishEnabled()); });
    }

    dutchTargetConnected(field: LocalisedField): void {
        this.enable(field, this.dutchEnabled());
    }

    englishTargetConnected(field: LocalisedField): void {
        this.enable(field, this.englishEnabled());
    }

    // An enabled language has to be filled in, which the label says with the same asterisk every other required field
    // carries. The server marks nothing here, because a disabled variant is never submitted.
    enable(field: LocalisedField, enabled: boolean): void {
        field.disabled = !enabled;
        Array.from(field.labels ?? []).forEach((label) => { label.classList.toggle('required', enabled); });
    }

    dutchEnabled(): boolean {
        if (this.hasDutchToggleTarget) {
            return this.dutchToggleTarget.checked;
        }

        return !this.hasDutchValue || this.dutchValue;
    }

    englishEnabled(): boolean {
        if (this.hasEnglishToggleTarget) {
            return this.englishToggleTarget.checked;
        }

        return !this.hasEnglishValue || this.englishValue;
    }
}
