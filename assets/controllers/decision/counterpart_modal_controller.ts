import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import type { Component } from '@symfony/ux-live-component';

/**
 * One modal serves every decision of the meeting: the trigger carries the decision it was opened for, and the lookup
 * beside this controller fills in the four hidden fields naming the virtual decision that was picked.
 *
 * It lives OUTSIDE the live component, like the shared confirmation does, so the re-render that follows the action
 * never touches the modal or leaves an orphaned backdrop behind.
 */
export default class extends Controller {
    static targets = ['meetingType', 'meetingNumber', 'point', 'number', 'input', 'preview', 'confirm'];

    declare readonly meetingTypeTarget: HTMLInputElement;
    declare readonly meetingNumberTarget: HTMLInputElement;
    declare readonly pointTarget: HTMLInputElement;
    declare readonly numberTarget: HTMLInputElement;
    declare readonly inputTarget: HTMLInputElement;
    declare readonly previewTarget: HTMLElement;
    declare readonly hasConfirmTarget: boolean;
    declare readonly confirmTarget: HTMLButtonElement;

    // Held with the component lookup to run the action on; null while nothing is armed.
    private _pending: {
        point: string;
        number: string;
        component: Promise<Component | null>;
    } | null = null;

    connect(): void {
        this.element.addEventListener('show.bs.modal', this._onShow);
        if (this.hasConfirmTarget) {
            this.confirmTarget.addEventListener('click', this._onConfirm);
        }
    }

    disconnect(): void {
        this.element.removeEventListener('show.bs.modal', this._onShow);
        if (this.hasConfirmTarget) {
            this.confirmTarget.removeEventListener('click', this._onConfirm);
        }
    }

    private readonly _onShow = (event: Event & { relatedTarget?: HTMLElement }): void => {
        this._reset();

        const trigger = event.relatedTarget;
        // The root is resolved here, while the trigger is still attached: a re-render can detach it between opening
        // the modal and confirming, and a detached node's closest() answers null.
        const root = trigger?.closest<HTMLElement>('[data-controller~="live"]') ?? null;

        this._pending = undefined !== trigger?.dataset.point
            && undefined !== trigger.dataset.number
            && null !== root
            ? {
                point: trigger.dataset.point,
                number: trigger.dataset.number,
                component: getComponent(root).catch(() => null),
            }
            : null;
    };

    private _reset(): void {
        this.inputTarget.value = '';
        this.meetingTypeTarget.value = '';
        this.meetingNumberTarget.value = '';
        this.pointTarget.value = '';
        this.numberTarget.value = '';
        this.previewTarget.classList.add('d-none');
    }

    private readonly _onConfirm = async (): Promise<void> => {
        if (null === this._pending || '' === this.meetingTypeTarget.value) {
            return;
        }

        const { point, number, component } = this._pending;
        const resolved = await component;
        if (null !== resolved) {
            resolved.action('linkVirtualCounterpart', {
                point,
                number,
                virtualType: this.meetingTypeTarget.value,
                virtualMeeting: this.meetingNumberTarget.value,
                virtualPoint: this.pointTarget.value,
                virtualDecision: this.numberTarget.value,
            });
        }
    };
}
