import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'button'];
    static values = { more: String, less: String };

    declare readonly hasContentTarget: boolean;
    declare readonly contentTarget: HTMLElement;
    declare readonly hasButtonTarget: boolean;
    declare readonly buttonTarget: HTMLElement;

    declare readonly moreValue: string;
    declare readonly lessValue: string;

    connect(): void {
        if (!this.hasContentTarget || !this.hasButtonTarget) {
            return;
        }

        // Wait for layout so clientHeight/scrollHeight are accurate, then reveal the button only when clamped.
        requestAnimationFrame(() => {
            if (this.contentTarget.scrollHeight > this.contentTarget.clientHeight + 1) {
                this.buttonTarget.classList.remove('d-none');
            }
        });
    }

    toggle(): void {
        const expanded = this.contentTarget.classList.toggle('expanded');
        this.buttonTarget.textContent = expanded ? this.lessValue : this.moreValue;
    }
}
