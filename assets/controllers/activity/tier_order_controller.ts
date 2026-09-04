import DragReorder from '../application/drag_reorder.ts';

export default class extends DragReorder {
    static targets = ['toggle', 'panel', 'value', 'entries', 'rank', 'tie', 'seats', 'seatsBox'];

    declare readonly hasToggleTarget: boolean;
    declare readonly toggleTarget: HTMLInputElement;
    declare readonly hasPanelTarget: boolean;
    declare readonly panelTarget: HTMLElement;
    declare readonly valueTarget: HTMLInputElement;

    connect(): void {
        const on = '' !== this.valueTarget.value.trim();
        if (this.hasToggleTarget) {
            this.toggleTarget.checked = on;
        }

        this.reveal(on);
        this.number();
    }

    toggle(): void {
        const on = this.hasToggleTarget && this.toggleTarget.checked;
        this.reveal(on);
        this.writeValue();
    }

    write(): void {
        this.writeValue();
    }

    tie(event: Event): void {
        const entry = (event.currentTarget as HTMLElement).closest<HTMLElement>('[data-tier-order-target="entry"]');
        if (null === entry) {
            return;
        }

        entry.dataset.tierOrderTiedParam = '1' === entry.dataset.tierOrderTiedParam ? '0' : '1';
        this.number();
        this.writeValue();
    }

    protected entrySelector(): string {
        return '[data-tier-order-target="entry"]';
    }

    protected reordered(): void {
        this.number();
        this.writeValue();
    }

    private reveal(on: boolean): void {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = !on;
        }
    }

    /**
     * Write the rank each entry sits on. The first entry cannot be tied to anything, so it starts rank one and is
     * offered no tie of its own.
     */
    private number(): void {
        let rank = 0;

        this.directEntries().forEach((entry, index) => {
            const tied = 0 !== index && '1' === entry.dataset.tierOrderTiedParam;
            if (0 === index) {
                entry.dataset.tierOrderTiedParam = '0';
            }

            if (!tied) {
                ++rank;
            }

            entry.classList.toggle('tier-order-entry--tied', tied);

            const badge = entry.querySelector<HTMLElement>('[data-tier-order-target="rank"]');
            if (null !== badge) {
                badge.textContent = String(rank);
            }

            const tie = entry.querySelector<HTMLButtonElement>('[data-tier-order-target="tie"]');
            if (null !== tie) {
                tie.hidden = 0 === index;
                tie.setAttribute('aria-pressed', tied ? 'true' : 'false');
            }

            // The seats are held for the rank, so only the row it starts on asks for them.
            const box = entry.querySelector<HTMLElement>('[data-tier-order-target="seatsBox"]');
            const seats = entry.querySelector<HTMLInputElement>('[data-tier-order-target="seats"]');
            if (null !== box && null !== seats) {
                box.classList.toggle('is-tied', tied);
                seats.disabled = tied;
                if (tied) {
                    seats.value = '';
                }
            }
        });
    }

    private writeValue(): void {
        if (this.hasToggleTarget && !this.toggleTarget.checked) {
            this.valueTarget.value = '';

            return;
        }

        const ranks: { tiers: string[]; seats: string }[] = [];
        this.directEntries().forEach((entry, index) => {
            const value = entry.dataset.tierOrderValueParam ?? '';
            if ('' === value) {
                return;
            }

            if (0 !== index && '1' === entry.dataset.tierOrderTiedParam && 0 !== ranks.length) {
                ranks[ranks.length - 1].tiers.push(value);

                return;
            }

            const seats = entry.querySelector<HTMLInputElement>('[data-tier-order-target="seats"]');

            ranks.push({tiers: [value], seats: seats?.value.trim() ?? ''});
        });

        this.valueTarget.value = ranks
            .map((rank) => rank.tiers.join('+') + ('' === rank.seats ? '' : ':' + rank.seats))
            .join(',');
    }
}
