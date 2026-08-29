// Minimal surface of CKEditor 5 we use. We vendor the official self-contained browser ESM bundle
// (assets/js/ckeditor5/ckeditor5.js, mapped as `ckeditor5` in importmap.php) instead of resolving the npm package
// through the importmap: the package's entry re-exports the @ckeditor/* source tree, which the jsDelivr resolver
// cannot ESM-ify. The browser bundle inlines everything (no dependency tree to keep patched).
export interface CkEditorInstance {
    getData(): string;
    execute(command: string, ...args: unknown[]): void;
    setData(data: string): void;
    destroy(): Promise<unknown>;
    focus(): void;
    enableReadOnlyMode(lockId: string): void;
    disableReadOnlyMode(lockId: string): void;
    model: { document: { on(event: string, callback: () => void): void } };
    ui: {
        componentFactory: {
            add(name: string, factory: (locale: unknown) => CkEditorButton): void;
        };
    };
}

export interface CkEditorButton {
    set(properties: Record<string, unknown>): void;
    on(event: string, callback: () => void): void;
}

export type CkEditorButtonConstructor = new (locale: unknown) => CkEditorButton;

export interface CkEditorModule {
    ClassicEditor: {
        create(element: HTMLElement, config: Record<string, unknown>): Promise<CkEditorInstance>;
    };
    [exportName: string]: unknown;
}

/**
 * The textarea stays in the DOM as the bound source of truth: the editor's data is written back to it and a bubbling
 * `input` event is fired, so both a Symfony form POST and a Live Component `data-model` binding keep working.
 *
 * `aborted` is asked again after every await, since the controller that started this may have disconnected (or
 * already have an editor) while the bundle was on its way; nothing is left behind when it says so.
 */
export async function createEditor(
    textarea: HTMLTextAreaElement,
    language: string,
    config: (module: CkEditorModule) => Record<string, unknown>,
    aborted: () => boolean,
): Promise<CkEditorInstance | null> {
    const ckeditor = (await import('ckeditor5')) as unknown as CkEditorModule;
    if (aborted()) {
        return null;
    }

    const settings = config(ckeditor);
    if ('nl' === language) {
        // The bundle ships English built in; the Dutch UI comes from a separate translations module.
        const dutch = await import('ckeditor5/translations/nl.js');
        if (aborted()) {
            return null;
        }

        settings.language = 'nl';
        settings.translations = [(dutch as { default: unknown }).default];
    }

    const editor = await ckeditor.ClassicEditor.create(textarea, settings);
    if (aborted()) {
        void editor.destroy();

        return null;
    }

    editor.model.document.on('change:data', () => {
        textarea.value = editor.getData();
        // Bubbles past a `data-live-ignore` boundary to the Live Component root, and is carried on form submit.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });

    return editor;
}
