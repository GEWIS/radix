import { LookupController } from './lookup.ts';

interface DecisionMatch {
    meeting_type: string;
    meeting_number: number;
    decision_point: number;
    decision_number: number;
    content: string;
}

/**
 * Picks the virtual decision that repeats a decision, inside the modal that asks for it. Unlike the annulment lookup
 * it gates nothing: the modal reads the hidden fields this fills in when the link is confirmed, and answers for
 * whether one was picked at all.
 */
export default class extends LookupController<DecisionMatch> {
    static targets = ['meetingType', 'meetingNumber', 'point', 'number', 'preview', 'previewNumber',
        'previewContent'];

    declare readonly meetingTypeTarget: HTMLInputElement;
    declare readonly meetingNumberTarget: HTMLInputElement;
    declare readonly pointTarget: HTMLInputElement;
    declare readonly numberTarget: HTMLInputElement;
    declare readonly previewTarget: HTMLElement;
    declare readonly previewNumberTarget: HTMLElement;
    declare readonly previewContentTarget: HTMLElement;

    protected label(match: DecisionMatch): string {
        const content = 100 < match.content.length ? `${match.content.substring(0, 100)}...` : match.content;

        return `${this.number(match)} ${content}`;
    }

    /**
     * Only a virtual decision repeats one, so the endpoint answers with nothing else.
     */
    protected parameters(): Record<string, string> {
        return { only_virtual: '1' };
    }

    protected chosenLabel(match: DecisionMatch): string {
        return this.number(match);
    }

    protected choose(match: DecisionMatch): void {
        this.meetingTypeTarget.value = match.meeting_type;
        this.meetingNumberTarget.value = String(match.meeting_number);
        this.pointTarget.value = String(match.decision_point);
        this.numberTarget.value = String(match.decision_number);

        this.previewNumberTarget.textContent = this.number(match);
        this.previewContentTarget.textContent = match.content;
        this.previewTarget.classList.remove('d-none');
    }

    private number(match: DecisionMatch): string {
        return `${match.meeting_type} ${match.meeting_number}.${match.decision_point}.${match.decision_number}`;
    }
}
