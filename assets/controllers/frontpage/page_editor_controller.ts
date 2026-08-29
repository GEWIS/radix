import { Controller } from '@hotwired/stimulus';
import {
    CkEditorButtonConstructor,
    CkEditorInstance,
    CkEditorModule,
    createEditor,
} from '../../js/ckeditor.ts';
import { flattenFloatingLabel } from '../../js/floating_label.ts';

/**
 * Writes HTML rather than the Markdown everything else on the site is written in (see
 * markdown_editor_controller.ts): a page is laid out with the tables, columns and images Markdown has no way to say.
 *
 * An image is picked out of the browser behind the toolbar's image button (page_images_controller.ts, which answers
 * the `page-editor:browse` this dispatches), never dropped into the text.
 */
export default class extends Controller {
    static values = {
        browseLabel: { type: String, default: 'Images' },
        language: { type: String, default: 'en' },
    };

    declare readonly browseLabelValue: string;
    declare readonly languageValue: string;

    private editor: CkEditorInstance | null = null;
    private aborted = false;
    private lastFocusedAt = 0;

    connect(): void {
        this.aborted = false;
        flattenFloatingLabel(this.textarea);
        this.host.addEventListener('focusin', this.noteFocus);
        void this.createEditor();
    }

    disconnect(): void {
        this.aborted = true;
        this.host.removeEventListener('focusin', this.noteFocus);
        void this.editor?.destroy();
        this.editor = null;
    }

    /** A page is written in two languages side by side, so the browser has to know which one to place a picture in. */
    get focusedAt(): number {
        return this.lastFocusedAt;
    }

    insertImage(url: string): void {
        if (null === this.editor) {
            return;
        }

        this.editor.execute('insertImage', { source: url });
        this.editor.focus();
    }

    private readonly noteFocus = (): void => {
        this.lastFocusedAt = Date.now();
    };

    private get textarea(): HTMLTextAreaElement {
        return this.element as HTMLTextAreaElement;
    }

    /** The editor draws itself next to the textarea, so what the writer clicks is a sibling. */
    private get host(): HTMLElement {
        return this.element.parentElement ?? this.element;
    }

    private async createEditor(): Promise<void> {
        if (null !== this.editor || this.aborted) {
            return;
        }

        const editor = await createEditor(
            this.textarea,
            this.languageValue,
            (c) => this.config(c),
            () => this.aborted || null !== this.editor,
        );

        if (null === editor) {
            return;
        }

        this.editor = editor;
    }

    /** Pressing the button says which editor the picture is for, cursor or no cursor. */
    private browse(): void {
        this.lastFocusedAt = Date.now();
        this.dispatch('browse', { prefix: 'page-editor', bubbles: true });
    }

    /** A plugin of the plainest kind CKEditor takes: a function it calls with the editor. */
    private browser(c: CkEditorModule): (editor: CkEditorInstance) => void {
        const ButtonView = c.ButtonView as CkEditorButtonConstructor;
        const icon = c.IconImage;
        const label = this.browseLabelValue;
        const open = (): void => this.browse();

        return function PageImageBrowser(editor: CkEditorInstance): void {
            editor.ui.componentFactory.add('pageImages', (locale) => {
                const button = new ButtonView(locale);

                button.set({
                    label,
                    icon,
                    tooltip: true,
                });
                button.on('execute', open);

                return button;
            });
        };
    }

    // 'GPL' license key: valid for this GPL-3.0 project (CKEditor 5 >= v44 requires a key).
    private config(c: CkEditorModule): Record<string, unknown> {
        return {
            licenseKey: 'GPL',
            plugins: [
                c.Essentials, c.Paragraph, c.Heading, c.Autoformat, c.PasteFromOffice,
                c.Bold, c.Italic, c.Underline, c.Strikethrough, c.Subscript, c.Superscript,
                c.Code, c.CodeBlock, c.RemoveFormat,
                c.List, c.TodoList, c.Indent, c.IndentBlock, c.Alignment, c.HorizontalLine,
                c.Link, c.AutoLink, c.BlockQuote,
                c.Table, c.TableToolbar, c.TableCaption, c.TableProperties, c.TableCellProperties,
                // No ImageUpload: dropping a file into the text would place a picture the website cannot serve yet.
                c.Image, c.ImageToolbar, c.ImageCaption, c.ImageStyle, c.ImageResize,
                c.FindAndReplace, c.SourceEditing, c.GeneralHtmlSupport,
            ],
            extraPlugins: [this.browser(c)],
            toolbar: [
                'heading', '|',
                'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', 'removeFormat', '|',
                'bulletedList', 'numberedList', 'todoList', 'outdent', 'indent', 'alignment', '|',
                'link', 'blockQuote', 'insertTable', 'pageImages', 'horizontalLine', 'code', 'codeBlock', '|',
                'findAndReplace', 'undo', 'redo', '|',
                'sourceEditing',
            ],
            // Whatever a page already holds is kept as it is, so opening an old page and saving it does not throw
            // half of it away. The sanitizer on save is the only thing that removes anything.
            htmlSupport: {
                allow: [{ name: /.*/, attributes: true, classes: true, styles: true }],
            },
            image: {
                toolbar: [
                    'imageTextAlternative', 'toggleImageCaption', '|',
                    'imageStyle:inline', 'imageStyle:block', 'imageStyle:side',
                ],
            },
            table: {
                contentToolbar: [
                    'tableColumn', 'tableRow', 'mergeTableCells',
                    'tableProperties', 'tableCellProperties', 'toggleTableCaption',
                ],
            },
        };
    }
}
