import { Controller } from '@hotwired/stimulus';

/**
 *   <div data-controller="signup-list">
 *     <input type="checkbox" data-signup-list-target="limited" data-action="change->signup-list#apply">
 *     <div data-signup-list-target="capacity">…capacity…</div>
 *     <div data-signup-list-target="methodBlock">
 *       <select data-signup-list-target="method" data-action="change->signup-list#apply">…</select>
 *       <div data-signup-list-target="conditional">
 *         <select data-signup-list-target="rule" data-action="change->signup-list#apply">…</select>
 *         <div data-signup-list-target="cutoffAt">…</div>
 *         <div data-signup-list-target="durationHours">…</div>
 *       </div>
 *       <div data-signup-list-target="external">…</div>
 *       <div data-signup-list-target="custom">…</div>
 *     </div>
 *   </div>
 *
 * A field tagged `required` is the one its block exists to ask for, so it is required exactly while that block is
 * shown; the server holds a list to the same rule (see SignupListType::validateAllocationMethod).
 */
export default class extends Controller {
    static targets = [
        'limited', 'capacity', 'methodBlock', 'method',
        'conditional', 'rule', 'cutoffAt', 'durationHours', 'external', 'custom', 'required',
    ];

    declare readonly hasLimitedTarget: boolean;
    declare readonly limitedTarget: HTMLInputElement;
    declare readonly hasMethodTarget: boolean;
    declare readonly methodTarget: HTMLSelectElement;
    declare readonly hasRuleTarget: boolean;
    declare readonly ruleTarget: HTMLSelectElement;
    declare readonly requiredTargets: (HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement)[];

    connect(): void {
        this.apply();
    }

    apply(): void {
        const limited = this.hasLimitedTarget && this.limitedTarget.checked;
        const method = this.hasMethodTarget ? this.methodTarget.value : '';
        const rule = this.hasRuleTarget ? this.ruleTarget.value : '';
        const conditional = limited && 'conditional-draw' === method;

        this.setHidden('capacity', !limited);
        this.setHidden('methodBlock', !limited);
        this.setHidden('conditional', !conditional);
        this.setHidden('external', !(limited && 'external-party' === method));
        this.setHidden('custom', !(limited && 'custom' === method));
        this.setHidden('cutoffAt', !(conditional && 'if-full-before' === rule));
        this.setHidden('durationHours', !(conditional && 'after-duration-open' === rule));

        this.markRequired();
    }

    setHidden(name: string, hidden: boolean): void {
        const target = this.targets.find(name);
        if (target instanceof HTMLElement) {
            target.hidden = hidden;
        }
    }

    // Only the label is marked, with the same asterisk every other required field carries: a field that is not asked
    // for is still submitted (hidden, not disabled) and is cleared server-side, so the browser must not refuse it.
    markRequired(): void {
        this.requiredTargets.forEach((field) => {
            const asked = null === field.closest('[hidden]');
            Array.from(field.labels ?? []).forEach((label) => { label.classList.toggle('required', asked); });
        });
    }
}
