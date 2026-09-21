import { Controller } from '@hotwired/stimulus';

interface Suggestion {
    name: string;
    next: number;
    latest: number | null;
    date: string | null;
    isoDate: string | null;
    missing: number[];
}

// A meeting cannot be renumbered once recorded, so a number typed by hand is never replaced.
/* stimulusFetch: 'lazy' */
export default class extends Controller<HTMLFormElement> {
    static targets = ['type', 'number', 'date', 'hint', 'dateWarning'];
    static values = {
        suggestions: Object,
        latestMessage: String,
        noneMessage: String,
        missingMessage: String,
        useLabel: String,
        dateWarningMessage: String,
    };

    declare readonly typeTarget: HTMLSelectElement;
    declare readonly numberTarget: HTMLInputElement;
    declare readonly dateTarget: HTMLInputElement;
    declare readonly hintTarget: HTMLElement;
    declare readonly dateWarningTarget: HTMLElement;

    declare readonly suggestionsValue: Record<string, Suggestion>;
    declare readonly latestMessageValue: string;
    declare readonly noneMessageValue: string;
    declare readonly missingMessageValue: string;
    declare readonly useLabelValue: string;
    declare readonly dateWarningMessageValue: string;

    private suggested = '';

    connect(): void {
        this.suggest();
    }

    suggest(): void {
        const suggestion = this.current();

        if (undefined === suggestion) {
            return;
        }

        if ('' === this.numberTarget.value || this.suggested === this.numberTarget.value) {
            this.numberTarget.value = String(suggestion.next);
            this.suggested = this.numberTarget.value;
        }

        this.renderHint(suggestion);
        this.checkDate();
    }

    use(event: Event): void {
        const number = String((event as CustomEvent & { params: { number?: number } }).params.number ?? '');

        if ('' === number) {
            return;
        }

        this.numberTarget.value = number;
        this.suggested = '';
        this.numberTarget.focus();
        this.checkDate();
    }

    checkDate(): void {
        const suggestion = this.current();
        const entered = this.dateTarget.value;
        const number = Number(this.numberTarget.value);

        if (
            undefined === suggestion
            || null === suggestion.isoDate
            || null === suggestion.latest
            || number <= suggestion.latest
            || '' === entered
            || entered >= suggestion.isoDate
        ) {
            this.dateWarningTarget.hidden = true;
            this.dateWarningTarget.textContent = '';

            return;
        }

        this.dateWarningTarget.textContent = this.fill(this.dateWarningMessageValue, suggestion);
        this.dateWarningTarget.hidden = false;
    }

    private current(): Suggestion | undefined {
        return this.suggestionsValue[this.typeTarget.value];
    }

    private renderHint(suggestion: Suggestion): void {
        const hint = this.hintTarget;
        hint.replaceChildren();

        if (null === suggestion.latest) {
            hint.append(this.fill(this.noneMessageValue, suggestion));

            return;
        }

        hint.append(this.fill(this.latestMessageValue, suggestion));

        if (0 === suggestion.missing.length) {
            return;
        }

        hint.append(' ', this.missingMessageValue);

        for (const number of suggestion.missing) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-link p-0 align-baseline';
            button.textContent = String(number);
            button.setAttribute('aria-label', this.useLabelValue.replace('%number%', String(number)));
            button.dataset.action = 'meeting-number#use';
            button.dataset.meetingNumberNumberParam = String(number);

            hint.append(' ', button);
        }
    }

    private fill(message: string, suggestion: Suggestion): string {
        return message
            .replaceAll('%type%', suggestion.name)
            .replace('%number%', String(suggestion.latest ?? ''))
            .replace('%date%', suggestion.date ?? '');
    }
}
