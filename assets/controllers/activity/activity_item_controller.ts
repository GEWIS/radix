import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    declare readonly urlValue: string;

    open(event: Event): void {
        if ((event.target as Element).closest('a, button')) {
            return;
        }

        window.location.assign(this.urlValue);
    }
}
