import { LookupController } from './lookup.ts';

export interface OrganMatch {
    meeting_type: string;
    meeting_number: number;
    decision_point: number;
    decision_number: number;
    subdecision_sequence: number;
    name: string;
    abbr: string;
}

export interface OrganInstallation {
    meeting_type: string;
    meeting_number: number;
    decision_point: number;
    decision_number: number;
    subdecision_sequence: number;
    function: string;
    functionName: string;
}

export interface OrganMember {
    member: { lidnr: number; fullName: string };
    installations: OrganInstallation[];
}

export interface OrganInfo extends OrganMatch {
    members: OrganMember[];
}

/**
 * Choosing a body fills in the reference to its foundation (which is what the form reads back) and then loads
 * who is currently installed in it, announced as `body-lookup:selected` so the page around it can show the
 * consequences before the decision is taken.
 */
/* stimulusFetch: 'lazy' */
export default class extends LookupController<OrganMatch> {
    static targets = ['meetingType', 'meetingNumber', 'decisionPoint', 'decisionNumber', 'sequence', 'submit'];
    static values = {
        infoUrl: String,
        lock: Boolean,
    };

    /**
     * When the form is re-rendered after a validation error, the hidden reference fields still carry the organ that
     * was selected. Detect that and reload the organ's info so the edit panel is restored.
     */
    connect(): void {
        super.connect();

        if (
            this.meetingTypeTarget.value !== ''
            && this.meetingNumberTarget.value !== ''
            && this.decisionPointTarget.value !== ''
            && this.decisionNumberTarget.value !== ''
            && this.sequenceTarget.value !== ''
        ) {
            const match: OrganMatch = {
                meeting_type: this.meetingTypeTarget.value,
                meeting_number: Number(this.meetingNumberTarget.value),
                decision_point: Number(this.decisionPointTarget.value),
                decision_number: Number(this.decisionNumberTarget.value),
                subdecision_sequence: Number(this.sequenceTarget.value),
                name: '',
                abbr: '',
            };

            void this.loadInfo(match);
        }
    }

    declare readonly meetingTypeTarget: HTMLInputElement;
    declare readonly meetingNumberTarget: HTMLInputElement;
    declare readonly decisionPointTarget: HTMLInputElement;
    declare readonly decisionNumberTarget: HTMLInputElement;
    declare readonly sequenceTarget: HTMLInputElement;
    declare readonly hasSubmitTarget: boolean;
    declare readonly submitTarget: HTMLButtonElement;

    declare readonly infoUrlValue: string;
    declare readonly lockValue: boolean;

    protected label(match: OrganMatch): string {
        const decision = `${match.meeting_type} ${match.meeting_number}.${match.decision_point}.${match.decision_number}`;

        return `${match.abbr} (${match.name}, ${decision})`;
    }

    protected choose(match: OrganMatch): void {
        this.meetingTypeTarget.value = match.meeting_type;
        this.meetingNumberTarget.value = String(match.meeting_number);
        this.decisionPointTarget.value = String(match.decision_point);
        this.decisionNumberTarget.value = String(match.decision_number);
        this.sequenceTarget.value = String(match.subdecision_sequence);

        void this.loadInfo(match);
    }

    private async loadInfo(match: OrganMatch): Promise<void> {
        // The five parts of a subdecision's address are the last five segments of the route, which is why they are
        // substituted positionally rather than by matching on values that also occur elsewhere in the path.
        const address = new URL(this.infoUrlValue, window.location.origin);
        const segments = address.pathname.split('/');

        [
            match.meeting_type,
            String(match.meeting_number),
            String(match.decision_point),
            String(match.decision_number),
            String(match.subdecision_sequence),
        ].forEach((value, index) => {
            segments[segments.length - 5 + index] = encodeURIComponent(value);
        });

        address.pathname = segments.join('/');

        const url = address.toString();

        const response = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            return;
        }

        const info = (await response.json()) as OrganInfo;

        this.dispatch('selected', { detail: info });

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = false;
        }

        // The organ is part of the decision's identity: once its membership is being edited, changing it would leave
        // the mutations pointing at the wrong organ.
        if (this.lockValue) {
            this.inputTarget.disabled = true;
        }
    }
}
