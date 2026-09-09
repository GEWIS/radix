import { Controller } from '@hotwired/stimulus';
import type { ActionEvent } from '@hotwired/stimulus';
import type MarkdownEditorController from '../application/markdown_editor_controller.ts';

/**
 * The buttons under the bulk-email composer that insert a placeholder into the message. Each button passes the token
 * to insert as an action parameter, so which placeholders are available is decided on the server.
 *
 * The editor is reached as an outlet rather than through the DOM: it is inside `data-live-ignore`, so its controller
 * instance is the only way to write to it, and a live re-render of these buttons does not affect it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static outlets = ['markdown-editor'];

    declare readonly markdownEditorOutlet: MarkdownEditorController;
    declare readonly hasMarkdownEditorOutlet: boolean;

    insert(event: ActionEvent): void {
        const token = String(event.params.token ?? '');

        if ('' === token || !this.hasMarkdownEditorOutlet) {
            return;
        }

        this.markdownEditorOutlet.insertText(token);
    }
}
