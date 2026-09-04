import { Controller } from '@hotwired/stimulus';

/**
 * Reordering a vertical list of entries by dragging: while an entry is dragged it is slotted live before the entry
 * under the cursor, and once it is let go the controller is told the order changed. Only DIRECT entries of the
 * wrapper move, so a list nested in an entry reorders on its own. Not a controller in its own right (no `_controller`
 * suffix, so it is not registered); the `sortable` and `tier-order` controllers build on it.
 */
export default abstract class DragReorder extends Controller {
    declare readonly entriesTarget: HTMLElement;

    private dragging: HTMLElement | null = null;

    /**
     * The selector that picks out one entry of this list.
     */
    protected abstract entrySelector(): string;

    /**
     * What to do once the entries stand in a new order (or an entry was added and the order has to be written out).
     */
    protected abstract reordered(): void;

    dragStart(event: DragEvent): void {
        const entry = (event.currentTarget as HTMLElement).closest<HTMLElement>(this.entrySelector());
        if (null === entry || entry.parentElement !== this.entriesTarget) {
            return;
        }

        this.dragging = entry;
        entry.classList.add('dragging');

        if (null !== event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Firefox only starts a drag once some data is set; the value itself is unused.
            event.dataTransfer.setData('text/plain', '');
            event.dataTransfer.setDragImage(entry, 0, 0);
        }
    }

    dragOver(event: DragEvent): void {
        if (null === this.dragging) {
            return;
        }

        event.preventDefault();

        const after = this.entryAfter(event.clientY);
        if (null === after) {
            this.entriesTarget.appendChild(this.dragging);
        } else if (after !== this.dragging) {
            this.entriesTarget.insertBefore(this.dragging, after);
        }
    }

    drop(event: DragEvent): void {
        event.preventDefault();
        this.reordered();
    }

    dragEnd(): void {
        if (null !== this.dragging) {
            this.dragging.classList.remove('dragging');
            this.dragging = null;
        }

        this.reordered();
    }

    protected directEntries(): HTMLElement[] {
        return Array.from(this.entriesTarget.querySelectorAll<HTMLElement>(':scope > ' + this.entrySelector()));
    }

    /**
     * The first direct entry whose vertical midpoint is below the cursor, or null to append at the end.
     */
    private entryAfter(y: number): HTMLElement | null {
        return this.directEntries().find((entry) => {
            if (entry === this.dragging) {
                return false;
            }

            const box = entry.getBoundingClientRect();

            return y < box.top + box.height / 2;
        }) ?? null;
    }
}
