import { Controller } from '@hotwired/stimulus';

/**
 * Toggles a class on the wrapper that CSS uses to hide documents with a single version. Purely client-side; nothing
 * is persisted.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller<HTMLElement> {
    static targets = ['all', 'revised', 'focus'];

    declare readonly allTarget: HTMLElement;
    declare readonly revisedTarget: HTMLElement;
    declare readonly focusTarget: HTMLElement;

    private documentTab?: HTMLElement;
    private decisionTab?: HTMLElement;
    private filterButtons?: HTMLElement;

    connect(): void {
        this.documentTab = this.element.querySelector('[data-bs-target="#meeting-documents-pane"]') as HTMLElement;
        this.decisionTab = this.element.querySelector('[data-bs-target="#meeting-decisions-pane"]') as HTMLElement;
        this.filterButtons = this.element.querySelector('.meeting-filter-buttons') as HTMLElement;
        if (!this.filterButtons) return;

        this.decisionTab?.addEventListener('shown.bs.tab', () => {
            this.filterButtons!.classList.add('d-none');
        });
        this.documentTab?.addEventListener('shown.bs.tab', () => {
            this.filterButtons!.classList.remove('d-none');
        });
    }

    showAll(): void {
        this.element.classList.remove('revised-only');
        this.allTarget.classList.add('active');
        this.revisedTarget.classList.remove('active');
        this.hideNoRevisedMessage();
        this.showDocumentsContent();
    }

    showRevised(): void {
        this.element.classList.add('revised-only');
        this.revisedTarget.classList.add('active');
        this.allTarget.classList.remove('active');
        this.checkForRevisedDocuments();
    }

    toggleFocus(): void {
        this.element.classList.toggle('focus-view');
        this.focusTarget.classList.toggle('active');
        if (this.element.classList.contains('revised-only')) {
            this.checkForRevisedDocuments();
        }
    }

    private checkForRevisedDocuments(): void {
        const items = this.element.querySelectorAll('[data-version-count]');
        let hasRevised = false;
        items.forEach(item => {
            if (parseInt(item.getAttribute('data-version-count') || '1', 10) > 1) {
                hasRevised = true;
            }
        });

        if (!hasRevised) {
            this.showNoRevisedMessage();
            this.hideDocumentsContent();
        } else {
            this.hideNoRevisedMessage();
            this.showDocumentsContent();
        }
    }

    private showNoRevisedMessage(): void {
        const message = this.element.querySelector('.no-revised-message');
        if (message) {
            message.classList.remove('d-none');
        }
    }

    private hideNoRevisedMessage(): void {
        const message = this.element.querySelector('.no-revised-message');
        if (message) {
            message.classList.add('d-none');
        }
    }

    private showDocumentsContent(): void {
        const pane = this.element.querySelector('#meeting-documents-pane');
        if (pane) {
            const panels = pane.querySelectorAll('.condensed-list-panel, .meeting-point-panel');
            panels.forEach(panel => {
                panel.classList.remove('d-none');
            });
        }
    }

    private hideDocumentsContent(): void {
        const pane = this.element.querySelector('#meeting-documents-pane');
        if (pane) {
            const panels = pane.querySelectorAll('.condensed-list-panel, .meeting-point-panel');
            panels.forEach(panel => {
                panel.classList.add('d-none');
            });
        }
    }
}