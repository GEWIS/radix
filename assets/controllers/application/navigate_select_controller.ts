import { Controller } from '@hotwired/stimulus';

export default class extends Controller<HTMLSelectElement> {
    public navigate(): void {
        const url = this.element.value;

        // An option that carries no URL -- a placeholder, or a choice the page renders without one -- must do
        // nothing; assigning an empty string reloads the current page instead.
        if ('' === url) {
            return;
        }

        window.location.assign(url);
    }
}
