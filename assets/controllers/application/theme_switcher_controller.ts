import { Controller } from '@hotwired/stimulus';

declare global {
    interface Window {
        gewisTheme?: {
            preferred(): string | null;
            apply(theme: string): void;
            store(theme: string): void;
        };
    }
}

/**
 * The theme buttons in the navigation menu.
 *
 * Applying a theme is not done here: that has to happen before the first paint, so it is in `color-modes.js`, which
 * the base layout inlines in the head. This marks which button is active and records a choice when one is pressed,
 * both of which need the document body and neither of which is urgent.
 *
 * The active button is found by comparing rather than by querying for the stored value, so a theme with no button
 * (a value left in storage by an older version) leaves all three unpressed instead of failing on a missing element.
 */
export default class extends Controller<HTMLElement> {
    connect(): void {
        this.mark(window.gewisTheme?.preferred() ?? null);
    }

    select(event: Event): void {
        const theme = (event.currentTarget as HTMLElement).getAttribute('data-bs-theme-value');
        if (null === theme) {
            return;
        }

        window.gewisTheme?.store(theme);
        window.gewisTheme?.apply(theme);
        this.mark(theme);
    }

    private mark(theme: string | null): void {
        this.element.querySelectorAll('[data-bs-theme-value]').forEach((button: Element): void => {
            const active = button.getAttribute('data-bs-theme-value') === theme;

            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
}
