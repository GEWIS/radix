import { Controller } from '@hotwired/stimulus';
import type { ActionEvent } from '@hotwired/stimulus';
import type PageEditorController from './page_editor_controller.ts';

declare global {
    interface Window {
        bootstrap: {
            Modal: {
                getOrCreateInstance(element: Element): {
                    show(relatedTarget?: Element): void;
                    hide(): void;
                };
            };
        };
    }
}

/**
 * The image browser behind the editor's toolbar button: what the page holds, and where a new image is uploaded.
 *
 * An upload is not finished when the file has arrived; a worker renders the sizes the website serves and says so over
 * Mercure, and each message re-renders the listing, which is what turns a waiting thumbnail into one that can be
 * placed. This sits on the modal rather than in the live component, so a re-render neither closes the dialog nor
 * drops the connection.
 */
export default class extends Controller<HTMLElement> {
    static outlets = ['page-editor'];
    static targets = ['input', 'error'];
    static values = {
        hubUrl: String,
        uploadUrl: String,
        uploadToken: String,
        page: { type: Number, default: 0 },
        flow: { type: String, default: '' },
    };

    declare readonly pageEditorOutlets: PageEditorController[];
    declare readonly inputTarget: HTMLInputElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;

    declare readonly hubUrlValue: string;
    declare readonly uploadUrlValue: string;
    declare readonly uploadTokenValue: string;
    declare readonly pageValue: number;
    declare readonly flowValue: string;

    private source: EventSource | null = null;

    connect(): void {
        if ('' === this.hubUrlValue) {
            return;
        }

        this.source = new EventSource(this.hubUrlValue, { withCredentials: true });
        this.source.onmessage = (): void => this.changed();
    }

    disconnect(): void {
        this.source?.close();
        this.source = null;
    }

    open(): void {
        this.hideError();
        this.modal().show();
    }

    /**
     * The two dialogs take turns rather than stand open at once: Bootstrap gives every modal the same stacking, and
     * the one closed first takes the body's scroll handling with it. The button goes to `show()` as the related
     * target, which is where confirm_modal_controller.ts reads what to ask and which action to run.
     */
    confirm(event: Event): void {
        const trigger = event.currentTarget as HTMLElement;
        const dialog = document.getElementById('confirm-modal');

        if (null === dialog) {
            return;
        }

        this.element.addEventListener(
            'hidden.bs.modal',
            () => {
                // Registered only once the question is going up, or a press that never got there would reopen this
                // dialog the next time somebody else asks one.
                dialog.addEventListener('hidden.bs.modal', () => this.open(), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(dialog).show(trigger);
            },
            { once: true },
        );

        this.modal().hide();
    }

    browse(): void {
        this.inputTarget.click();
    }

    picked(): void {
        const file = this.inputTarget.files?.item(0) ?? null;
        if (null === file) {
            return;
        }

        void this.upload(file);
    }

    dragOver(event: DragEvent): void {
        event.preventDefault();
        this.element.classList.add('upload-dragover');
    }

    dragLeave(): void {
        this.element.classList.remove('upload-dragover');
    }

    dropped(event: DragEvent): void {
        event.preventDefault();
        this.element.classList.remove('upload-dragover');

        const file = event.dataTransfer?.files.item(0) ?? null;
        if (null === file) {
            return;
        }

        void this.upload(file);
    }

    insert(event: ActionEvent): void {
        const url = String(event.params.url ?? '');
        if ('' === url) {
            return;
        }

        this.editor()?.insertImage(url);
        this.modal().hide();
    }

    private async upload(file: File): Promise<void> {
        this.hideError();

        const body = new FormData();
        body.append('image', file);
        body.append('_csrf_token', this.uploadTokenValue);
        if (0 !== this.pageValue) {
            body.append('page', String(this.pageValue));
        }
        if ('' !== this.flowValue) {
            body.append('flow', this.flowValue);
        }

        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { 'Sec-Fetch-Site': 'same-origin' },
            });

            const stored = (await response.json()) as { url?: string; error?: string };
            if (!response.ok || 'string' !== typeof stored.url) {
                this.showError(stored.error ?? '');

                return;
            }
        } catch {
            this.showError('');

            return;
        } finally {
            // Or picking the same file again after a failure would not count as a change.
            this.inputTarget.value = '';
        }

        this.changed();
    }

    private modal(): { show(relatedTarget?: Element): void; hide(): void } {
        return window.bootstrap.Modal.getOrCreateInstance(this.element);
    }

    private changed(): void {
        this.dispatch('changed', { prefix: 'page-images', bubbles: true });
    }

    private editor(): PageEditorController | null {
        return this.pageEditorOutlets.reduce<PageEditorController | null>(
            (chosen, editor) => (null === chosen || editor.focusedAt > chosen.focusedAt ? editor : chosen),
            null,
        );
    }

    private showError(message: string): void {
        if (!this.hasErrorTarget) {
            return;
        }

        // The element carries the sentence to fall back on.
        if ('' !== message) {
            this.errorTarget.textContent = message;
        }

        this.errorTarget.classList.remove('d-none');
    }

    private hideError(): void {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.classList.add('d-none');
    }
}
