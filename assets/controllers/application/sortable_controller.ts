import DragReorder from './drag_reorder.ts';

/**
 * On drop the dragged entry is moved in the DOM and every entry's position input is rewritten to its new index. A
 * capture-phase submit listener reindexes once more so entries added after the last drag (whose prototype position is
 * 0) are numbered too. Only DIRECT entries of this controller's wrapper are considered, so a nested collection (a
 * choice field's options inside a question) reorders independently.
 *
 * ```
 * <div data-controller="form-collection sortable">
 *     <div data-form-collection-target="entries" data-sortable-target="entries"
 *          data-action="dragover->sortable#dragOver drop->sortable#drop">
 *         <div data-form-collection-target="entry">
 *             <span draggable="true" data-action="dragstart->sortable#dragStart dragend->sortable#dragEnd">...</span>
 *             <input type="hidden" data-sortable-target="position">
 *         </div>
 *     </div>
 * </div>
 * ```
 */
/* stimulusFetch: 'lazy' */
export default class extends DragReorder {
    static targets = ['entries', 'position'];

    declare readonly positionTargets: HTMLInputElement[];

    private form: HTMLFormElement | null = null;
    private readonly reindexOnSubmit = (): void => this.reindex();

    connect(): void {
        // Reindex right before the form serializes, so newly added entries (never dragged) also get their order.
        this.form = this.element.closest('form');
        this.form?.addEventListener('submit', this.reindexOnSubmit, true);
    }

    disconnect(): void {
        this.form?.removeEventListener('submit', this.reindexOnSubmit, true);
    }

    protected entrySelector(): string {
        return '[data-form-collection-target="entry"]';
    }

    protected reordered(): void {
        this.reindex();
    }

    private reindex(): void {
        // positionTargets are in document order, one per direct entry -- a nested collection's position inputs bind to its
        // own inner sortable controller, not this one.
        this.positionTargets.forEach((input, index) => {
            input.value = String(index);
        });
    }
}
