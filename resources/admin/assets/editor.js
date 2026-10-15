// Upgrades every <textarea data-rich-text> to a CKEditor 5 editor (GPL build, vendored, no network calls). Without
// JavaScript, or when the editor fails to start, the textarea stays a plain HTML field; the server sanitizes it either
// way. The settings come from the textarea's data-editor JSON (EditorConfig): the interface language, uploads and the
// media library (only with "media.upload"), the theme's styles, ready-made blocks, classes, font sizes, fonts,
// palette and markers, the code block and text part languages, and the video players.
// Features write classes where CKEditor can and inline styles only where it cannot (colours, font families, image and
// table sizes, table borders); the server keeps those styles through its own allow-list (StyleAttributeSanitizer).
// CSP: this module never injects markup, sets attributes or styles, and holds no network address. Its one markup
// string is the admin preview of a video (an iframe with a class, no style).
import {
    ClassicEditor, Essentials, Notification, Paragraph, Heading, Bold, Italic, Underline, Strikethrough, Subscript,
    Superscript, Code, RemoveFormat, FontSize, FontFamily, FontColor, FontBackgroundColor, Highlight, Alignment, Indent,
    IndentBlock, Link, AutoLink, List, ListProperties, TodoList, BlockQuote, CodeBlock, HorizontalLine, Table,
    TableToolbar, TableCaption, TableProperties, TableCellProperties, TableColumnResize, Style, GeneralHtmlSupport,
    MediaEmbed, Image, ImageToolbar, ImageCaption, ImageTextAlternative, ImageStyle, ImageResize, LinkImage,
    ImageUpload, SpecialCharacters, SpecialCharactersEssentials, TextPartLanguage, FindAndReplace, ShowBlocks,
    Fullscreen, WordCount, SourceEditing, PasteFromOffice, Autoformat, Plugin, ButtonView, createDropdown,
    addListToDropdown, Collection, UIModel, IconImageAssetManager, IconTemplate,
} from './vendor/ckeditor5-48.5.2.js';

// The elements of the server's allow-list that keep a class (RichTextSanitizer): the theme's own classes may appear
// on any of them, also on <div>, the one container CKEditor does not model itself. The classes of the editor's
// features (alignment, sizes, markers, image styles …) are modelled by the features and never go through here.
const CLASS_ELEMENTS = [
    'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sub', 'sup',
    'blockquote', 'code', 'pre', 'hr', 'ul', 'ol', 'li', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot',
    'tr', 'caption', 'th', 'td', 'a', 'span', 'mark',
];

// The full toolbar in two rows ('-' breaks the row). Frequent items stay on it; the rest sit in three dropdown groups
// ({group, icon, items}: the label comes from settings.labels[group], the icon is one of CKEditor's named toolbar
// icons, no network). Each row fits the editor of the entry form at a 1280 px window (measured; the heading and style
// dropdowns are narrowed in admin.css), and a narrower editor wraps each row on its own. Items whose feature is off
// for the field are left out (toolbarItems()), together with a separator that would then be doubled or stand at the
// edge of a row, and a group that would be empty.
const TOOLBAR = [
    'undo', 'redo', '|', 'heading', 'style', '|', 'bold', 'italic', 'fontSize', 'fontColor',
    {
        group: 'formatting',
        icon: 'text',
        items: [
            'underline', 'strikethrough', 'subscript', 'superscript', 'code', '|', 'fontFamily', 'fontBackgroundColor',
            'highlight', '|', 'outdent', 'indent', '|', 'textPartLanguage', 'removeFormat',
        ],
    },
    '|', 'alignment',
    '-',
    'bulletedList', 'numberedList', 'todoList', '|',
    'link', 'mediaLibrary', 'mediaEmbed', 'insertTable', 'templates',
    { group: 'insert', icon: 'plus', items: ['blockQuote', 'codeBlock', 'horizontalLine', 'specialCharacters'] },
    '|', { group: 'tools', icon: 'threeVerticalDots', items: ['findAndReplace', 'selectAll', 'showBlocks'] },
    'sourceEditing', 'fullscreen',
];

// The toolbar of a minimal field (Field::minimal(): summaries, teasers); the server keeps only what it writes.
const MINIMAL_TOOLBAR = ['undo', 'redo', '|', 'bold', 'italic', 'fontSize', 'fontColor'];

const IMAGE_TOOLBAR = [
    'imageStyle:inline', 'imageStyle:alignLeft', 'imageStyle:block', 'imageStyle:alignRight', 'imageStyle:full', '|',
    'resizeImage', '|', 'imageTextAlternative', 'toggleImageCaption', 'linkImage',
];

const TABLE_TOOLBAR = ['tableColumn', 'tableRow', 'mergeTableCells', '|', 'tableProperties', 'tableCellProperties', '|', 'toggleTableCaption'];

// Classes, never inline styles, for alignment and indentation (EditorClasses::ALIGNMENT, ::INDENT on the server).
const ALIGNMENTS = ['left', 'center', 'right', 'justify'];
const INDENT_CLASSES = ['indent-1', 'indent-2', 'indent-3', 'indent-4'];

// The editor's interface translations, by interface language (vendored with the build, loaded only when needed).
const TRANSLATIONS = {
    ru: () => import('./vendor/ckeditor5-48.5.2-ru.js'),
    he: () => import('./vendor/ckeditor5-48.5.2-he.js'),
    de: () => import('./vendor/ckeditor5-48.5.2-de.js'),
    fr: () => import('./vendor/ckeditor5-48.5.2-fr.js'),
};

// The video links the server accepts (VideoUrl), ids captured in group 1. As there, the scheme and the host match in
// any letter case, the path and the query only as written (anyCase() turns the host's letters into [xX] classes; the
// ids keep their own classes). CKEditor also retries a link without a lower-case "http(s)://" and "www.".
const anyCase = (source) => source.replace(/[a-z]/g, (letter) => `[${letter}${letter.toUpperCase()}]`);
const videoUrl = (host, path) => new RegExp(`^(?:${anyCase('https?')}:\\/\\/)?(?:${anyCase(host)})${path}$`);
const YOUTUBE_HOST = '(?:(?:www|m)\\.)?youtube\\.com|(?:www\\.)?youtube-nocookie\\.com';
const VIMEO_HOST = '(?:www\\.)?vimeo\\.com';
const YOUTUBE_URLS = [
    videoUrl(YOUTUBE_HOST, '\\/watch\\?(?:[^#]*&)?v=([A-Za-z0-9_-]{11})(?:[&#].*)?'),
    videoUrl(YOUTUBE_HOST, '\\/(?:embed|shorts|live)\\/([A-Za-z0-9_-]{11})(?:[?#].*)?'),
    videoUrl('youtu\\.be', '\\/([A-Za-z0-9_-]{11})\\/?(?:[?#].*)?'),
];
const VIMEO_URLS = [
    videoUrl(VIMEO_HOST, '\\/(?:channels\\/[A-Za-z0-9_-]+\\/)?([0-9]{1,12})(?:[?#].*)?'),
    videoUrl('player\\.vimeo\\.com', '\\/video\\/([0-9]{1,12})(?:[?#].*)?'),
];

const preview = (src) => `<iframe class="admin-editor-video" src="${src}" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe>`;

// The items without those in `off`, and without separators that would be doubled or stand at the edge of a row;
// groups get their label and lose the items that are off, and an empty group goes.
function toolbarItems(list, off, labels) {
    const items = [];
    for (const item of list) {
        if (typeof item === 'object') {
            const grouped = toolbarItems(item.items, off, labels);
            if (grouped.length > 0) {
                items.push({ label: labels[item.group], icon: item.icon, items: grouped });
            }
        } else if (!off.has(item) && !(item === '|' && (items.length === 0 || items.at(-1) === '|' || items.at(-1) === '-'))) {
            if (item === '-' && items.at(-1) === '|') {
                items.pop();
            }
            items.push(item);
        }
    }
    if (items.at(-1) === '|') {
        items.pop();
    }

    return items;
}

// The admin layout gives htmx the CSRF token in <body hx-headers>; uploads send it the same way.
function csrfToken() {
    try {
        return JSON.parse(document.body.getAttribute('hx-headers') || '{}')['X-CSRF-Token'] || '';
    } catch {
        return '';
    }
}

// The editor asking the media picker (media.js) for an image; the answer arrives as a "media-picked" event.
let picking = null;

document.addEventListener('media-picked', (event) => {
    const editor = picking;
    picking = null;
    if (editor === null || !event.detail || !event.detail.url) {
        return;
    }
    editor.model.change((writer) => {
        const image = writer.createElement('imageBlock', { src: event.detail.url, alt: event.detail.alt || '' });
        editor.model.insertObject(image, null, null, { setSelection: 'on' });
        if (image.parent !== null) {
            placeCaretAfter(editor, writer, image);
        }
    });
    editor.editing.view.focus();
});

// After an inserted object (an image, a ready-made block) the caret moves into the paragraph that follows it, a new
// one when the object ends its parent or is followed by something else: otherwise the next insert would replace a
// selected image, or land inside a block at the end of the text.
function placeCaretAfter(editor, writer, element) {
    const next = element.nextSibling;
    if (next !== null && next.is('element', 'paragraph')) {
        writer.setSelection(next, 0);

        return;
    }
    if (editor.model.schema.checkChild(element.parent, 'paragraph')) {
        const paragraph = writer.createElement('paragraph');
        writer.insert(paragraph, element, 'after');
        writer.setSelection(paragraph, 0);
    }
}

// The top-level node a ready-made block became: the node before the end of the inserted range, on the level where
// the insertion started.
function insertedNode(range) {
    const parent = range.start.parent;
    if (range.end.parent === parent) {
        return range.end.nodeBefore;
    }
    let node = range.end.parent;
    while (node !== null && node.parent !== parent) {
        node = node.parent;
    }

    return node;
}

function mediaLibraryPlugin(settings, contentLocale) {
    return class MediaLibrary extends Plugin {
        static get pluginName() {
            return 'MediaLibrary';
        }

        init() {
            const editor = this.editor;
            editor.ui.componentFactory.add('mediaLibrary', (locale) => {
                const button = new ButtonView(locale);
                button.set({ label: settings.labels.library, icon: IconImageAssetManager, tooltip: true });
                button.on('execute', () => {
                    picking = editor;
                    document.dispatchEvent(new CustomEvent('media-pick', { detail: { locale: contentLocale } }));
                });

                return button;
            });
        }
    };
}

function templatesPlugin(settings) {
    return class Templates extends Plugin {
        static get pluginName() {
            return 'Templates';
        }

        init() {
            const editor = this.editor;
            editor.ui.componentFactory.add('templates', (locale) => {
                const dropdown = createDropdown(locale);
                dropdown.buttonView.set({ label: settings.labels.templates, icon: IconTemplate, tooltip: true });
                const items = new Collection();
                settings.templates.forEach((template, index) => {
                    items.add({ type: 'button', model: new UIModel({ label: template.label, withText: true, templateIndex: index }) });
                });
                addListToDropdown(dropdown, items);
                dropdown.on('execute', (event) => this.insert(settings.templates[event.source.templateIndex].html));

                return dropdown;
            });
        }

        insert(html) {
            const editor = this.editor;
            const fragment = editor.data.toModel(editor.data.processor.toView(html));
            editor.model.change((writer) => {
                const range = editor.model.insertContent(fragment);
                const node = insertedNode(range);
                if (node !== null && node.is('element')) {
                    placeCaretAfter(editor, writer, node);
                }
            });
            editor.editing.view.focus();
        }
    };
}

// Uploads a dropped or pasted image to the media library (POST /admin/media, as the library's own upload form) and
// answers with its /uploads/ address; anything that comes back as a non-image is refused.
class UploadAdapter {
    constructor(loader, settings, messages) {
        this.loader = loader;
        this.settings = settings;
        this.messages = messages;
        this.request = null;
    }

    async upload() {
        const file = await this.loader.file;

        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();
            this.request = request;
            request.open('POST', this.settings.url);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-CSRF-Token', csrfToken());
            request.upload.addEventListener('progress', (progress) => {
                if (progress.lengthComputable) {
                    this.loader.uploadTotal = progress.total;
                    this.loader.uploaded = progress.loaded;
                }
            });
            request.addEventListener('load', () => {
                let response = null;
                try {
                    response = JSON.parse(request.responseText);
                } catch {
                    response = null;
                }
                const media = response !== null && Array.isArray(response.media) ? response.media[0] : null;
                if (request.status === 200 && media && typeof media.mime === 'string' && media.mime.startsWith('image/')) {
                    this.messages.clear();
                    resolve({ default: media.url });

                    return;
                }
                reject((response !== null && Array.isArray(response.errors) && response.errors[0]) || this.settings.failed);
            });
            request.addEventListener('error', () => reject(this.settings.failed));
            request.addEventListener('abort', () => reject());
            const body = new FormData();
            body.append('files[]', file);
            request.send(body);
        });
    }

    abort() {
        this.request?.abort();
    }
}

function uploadPlugin(settings, messages) {
    return function UploadAdapterPlugin(editor) {
        editor.plugins.get('FileRepository').createUploadAdapter = (loader) => new UploadAdapter(loader, settings, messages);
    };
}

// The message line under an editor, after `anchor` (textContent only: server messages are never parsed as markup).
function messageLine(anchor) {
    const line = document.createElement('p');
    line.className = 'admin-field-error';
    line.role = 'alert';
    line.hidden = true;
    anchor.after(line);

    return {
        show(text) {
            line.textContent = text;
            line.hidden = false;
        },
        clear() {
            line.hidden = true;
        },
    };
}

// The length of the visible text as the server measures it for ->max() (RichText::text(), mb_strlen()): every block
// and soft break separates words by one space, an inline image adds nothing (strip_tags() drops <img>), runs of
// whitespace (no-break spaces included: JavaScript's \s holds them) become one space, the ends are trimmed, and code
// points count.
function visibleLength(editor) {
    const parts = [];
    for (const item of editor.model.createRangeIn(editor.model.document.getRoot()).getItems()) {
        parts.push(item.is('$textProxy') ? item.data : item.is('element', 'imageInline') ? '' : ' ');
    }

    return [...parts.join('').replace(/\s+/gu, ' ').trim()].length;
}

// A field with ->max() (data-max): "412 / 500" at the end of the word count line, red past the limit. The counter is
// not a live region (it would speak on every keystroke); a hidden status line announces only the crossing of the limit,
// either way. The server stays the judge (its field error on save).
function characterCounter(editor, container, limit, labels) {
    const item = document.createElement('div');
    item.className = 'admin-editor-count';
    const visible = document.createElement('span');
    visible.ariaHidden = 'true';
    visible.dir = 'ltr';
    const spoken = document.createElement('span');
    spoken.className = 'admin-visually-hidden';
    const status = document.createElement('span');
    status.className = 'admin-visually-hidden';
    status.role = 'status';
    item.append(visible, spoken, status);
    container.append(item);
    const words = (label, count) => label.replace('#count', String(count)).replace('#max', String(limit));
    let wasOver = null;
    const update = () => {
        const count = visibleLength(editor);
        const over = count > limit;
        const text = words(over ? labels.countOver : labels.count, count);
        visible.textContent = `${count} / ${limit}`;
        spoken.textContent = text;
        item.classList.toggle('admin-editor-count-over', over);
        if (wasOver !== null && over !== wasOver) {
            status.textContent = text;
        }
        wasOver = over;
    };
    editor.model.document.on('change:data', update);
    update();
}

// The swatches of the colour pickers: the theme's palette, or CKEditor's own when the theme declares none (null).
function swatches(settings, ...keys) {
    return settings.colors === null ? {} : Object.fromEntries(keys.map((key) => [key, settings.colors]));
}

async function start(textarea) {
    const settings = JSON.parse(textarea.dataset.editor || '{}');
    const form = textarea.closest('form');
    const contentLocale = (form && form.dataset.contentLocale) || settings.language;
    const minimal = settings.profile === 'minimal';
    // A field with ->max() counts its visible text itself (characterCounter()) instead of CKEditor's character count.
    const limit = Number.parseInt(textarea.dataset.max ?? '', 10);
    const counted = Number.isInteger(limit) && limit > 0;
    // Notification is listed itself: only ImageUpload requires it, and the warnings below listen to it always.
    const plugins = minimal ? [Essentials, Notification, Paragraph, Bold, Italic, FontColor, WordCount, PasteFromOffice] : [
        Essentials, Notification, Paragraph, Heading, Bold, Italic, Underline, Strikethrough, Subscript, Superscript,
        Code, RemoveFormat, FontColor, FontBackgroundColor, Alignment, Indent, IndentBlock, Link, AutoLink, List,
        ListProperties, TodoList, BlockQuote, CodeBlock, HorizontalLine, Table, TableToolbar, TableCaption,
        TableProperties, TableCellProperties, TableColumnResize, GeneralHtmlSupport, MediaEmbed, Image, ImageToolbar,
        ImageCaption, ImageTextAlternative, ImageStyle, ImageResize, LinkImage, SpecialCharacters,
        SpecialCharactersEssentials, TextPartLanguage, FindAndReplace, ShowBlocks, Fullscreen, WordCount,
        SourceEditing, PasteFromOffice, Autoformat,
    ];
    // A feature the field cannot use, or the theme turns off (an empty list), loses its plugin and its button.
    const off = new Set();
    const fontSize = ['fontSize', FontSize, settings.fontSizes === null || settings.fontSizes.length > 0];
    const optional = minimal ? [fontSize] : [
        ['style', Style, settings.classStyles.length > 0],
        fontSize,
        ['fontFamily', FontFamily, settings.fonts === null || settings.fonts.length > 0],
        ['highlight', Highlight, settings.markers === null || settings.markers.length > 0],
        ['mediaLibrary', mediaLibraryPlugin(settings, contentLocale), settings.library],
        ['templates', templatesPlugin(settings), settings.templates.length > 0],
    ];
    for (const [item, plugin, on] of optional) {
        if (on) {
            plugins.push(plugin);
        } else {
            off.add(item);
        }
    }
    let messages = null;
    if (settings.upload && !minimal) {
        plugins.push(ImageUpload, uploadPlugin(settings.upload, { clear: () => messages?.clear() }));
    }
    // English is built in; CKEditor refuses an empty translations list, so the key is set only for the languages of
    // TRANSLATIONS. A Hebrew interface also turns the editor's own interface right to left.
    const translations = Object.hasOwn(TRANSLATIONS, settings.language) ? { translations: [(await TRANSLATIONS[settings.language]()).default] } : {};
    const colorPicker = { format: 'hex' };

    const editor = await ClassicEditor.create(textarea, {
        ...translations,
        licenseKey: 'GPL',
        language: { ui: settings.language, content: contentLocale, textPartLanguage: settings.textLanguages },
        label: textarea.labels.length > 0 ? textarea.labels[0].textContent.trim() : '',
        plugins,
        toolbar: { items: toolbarItems(minimal ? MINIMAL_TOOLBAR : TOOLBAR, off, settings.labels), shouldNotGroupWhenFull: true },
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' },
            ],
        },
        style: { definitions: settings.classStyles },
        // Font sizes are classes; font families are inline styles, kept by the server only from the theme's list.
        fontSize: { supportAllValues: false, ...(settings.fontSizes === null ? {} : { options: ['default', ...settings.fontSizes] }) },
        fontFamily: {
            supportAllValues: false,
            ...(settings.fonts === null ? {} : {
                options: ['default', ...settings.fonts.map((font) => ({
                    title: font.title,
                    model: font.family,
                    view: { name: 'span', styles: { 'font-family': font.family }, priority: 7 },
                }))],
            }),
        },
        fontColor: { ...swatches(settings, 'colors'), columns: 5, colorPicker },
        fontBackgroundColor: { ...swatches(settings, 'colors'), columns: 5, colorPicker },
        highlight: settings.markers === null ? {} : { options: settings.markers },
        alignment: { options: ALIGNMENTS.map((name) => ({ name, className: `text-align-${name}` })) },
        indentBlock: { classes: INDENT_CLASSES },
        list: { properties: { styles: { useAttribute: true }, startIndex: true, reversed: true } },
        codeBlock: { languages: settings.codeLanguages },
        htmlSupport: { allow: CLASS_ELEMENTS.map((name) => ({ name, classes: settings.classes })) },
        link: {
            defaultProtocol: settings.linkProtocol,
            decorators: {
                openInNewTab: { mode: 'manual', label: settings.labels.newTab, attributes: { target: '_blank', rel: 'noopener noreferrer' } },
            },
        },
        table: {
            contentToolbar: TABLE_TOOLBAR,
            tableProperties: { ...swatches(settings, 'borderColors', 'backgroundColors'), colorPicker },
            tableCellProperties: { ...swatches(settings, 'borderColors', 'backgroundColors'), colorPicker },
        },
        image: {
            toolbar: IMAGE_TOOLBAR,
            styles: {
                options: [
                    'inline', 'block', 'alignLeft', 'alignRight',
                    { name: 'full', title: settings.labels.imageFull, icon: 'full', modelElements: ['imageBlock'], className: 'image-style-full' },
                ],
            },
            resizeUnit: '%',
            resizeOptions: [
                { name: 'resizeImage:original', value: null },
                { name: 'resizeImage:custom', value: 'custom' },
                { name: 'resizeImage:25', value: '25' },
                { name: 'resizeImage:50', value: '50' },
                { name: 'resizeImage:75', value: '75' },
            ],
            upload: { types: ['jpeg', 'png', 'webp', 'gif'] },
        },
        mediaEmbed: {
            previewsInData: false,
            providers: [
                { name: 'youtube', url: YOUTUBE_URLS, html: (match) => preview(settings.video.youtube + match[1]) },
                { name: 'vimeo', url: VIMEO_URLS, html: (match) => preview(settings.video.vimeo + match[1]) },
            ],
        },
        fullscreen: { menuBar: { isVisible: false } },
        wordCount: { displayWords: true, displayCharacters: !counted },
    });

    if (minimal) {
        editor.ui.element.classList.add('admin-editor-minimal');
    }
    // The word count sits under the editor, the message line under the word count.
    const wordCount = editor.plugins.get('WordCount').wordCountContainer;
    editor.ui.element.after(wordCount);
    messages = messageLine(wordCount);
    if (counted) {
        characterCounter(editor, wordCount, limit, settings.labels);
    }
    // Upload failures and other warnings go to the message line instead of a browser alert box.
    editor.plugins.get('Notification').on('show:warning', (event, data) => {
        messages.show(typeof data.message === 'string' && data.message !== '' ? data.message : (settings.upload ? settings.upload.failed : ''));
        event.stop();
    }, { priority: 'high' });

    // Saving while an image uploads would store it without an address: block the submit and say why. In source mode
    // the typed HTML goes back into the editor first. Capture phase: before CKEditor's own submit handler. An autosave
    // (autosave.js) asks the same through the cancelable "entry-form-sync" event, quietly: it tries again later.
    const flush = (event, quiet) => {
        if (editor.plugins.has('PendingActions') && editor.plugins.get('PendingActions').hasAny) {
            event.preventDefault();
            if (!quiet) {
                messages.show(settings.upload ? settings.upload.pending : '');
            }

            return;
        }
        const source = editor.plugins.has('SourceEditing') ? editor.plugins.get('SourceEditing') : null;
        if (source !== null && source.isSourceEditingMode) {
            source.updateEditorData();
        }
        editor.updateSourceElement();
    };
    if (form !== null) {
        form.addEventListener('submit', (event) => flush(event, false), { capture: true });
        form.addEventListener('entry-form-sync', (event) => flush(event, true));
        // An edit in the editor is a change of the form: "input" on the textarea tells autosave.js. Data that only
        // differs from the loaded text by the editor's own normalising is no change.
        const loaded = editor.getData();
        let changed = false;
        editor.model.document.on('change:data', () => {
            if (!changed && editor.getData() === loaded) {
                return;
            }
            changed = true;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    return editor;
}

// Machine translation of the editor's content (translate.js, Plan 38), block by block: each element holding text (a
// paragraph, heading, list item, table cell's paragraph, caption…; never a code block) goes to the translator as its
// inline HTML from the editor's own data pipeline (stringify), so a sentence broken by bold or a link is translated
// whole; the answer is parsed back by the same pipeline (parse, no markup injection) and taken only when it has exactly the
// block's own markup (sameMarkup(): the same inline elements, text only with attribute sets the block had, so no new
// element or attribute and no changed link address). Otherwise, and for a block with inline code, the block's text
// nodes are translated one by one (code left alone). The editor is read-only meanwhile, so the blocks stay as read.
// Resolves to {apply, revert, release}: apply() writes every block in one model change (one undo step), revert()
// undoes that batch, release() only unlocks the editor, so translate.js applies all fields or none.
const TRANSLATION_LOCK = 'machine-translation';

const attributesKey = (node) => JSON.stringify([...node.getAttributes()].sort(([a], [b]) => (a < b ? -1 : (a > b ? 1 : 0))));

// The markup of an element's or fragment's inline content: its inline elements (name and attributes, in order of
// their keys) and the attribute sets of its texts.
function inlineMarkup(parent) {
    const texts = new Set();
    const elements = [];
    for (const child of parent.getChildren()) {
        if (child.is('$text')) {
            texts.add(attributesKey(child));
        } else {
            elements.push(child.name + attributesKey(child) + (child.childCount > 0 ? '+' : ''));
        }
    }

    return { texts, elements: elements.sort() };
}

function sameMarkup(source, translated) {
    const before = inlineMarkup(source);
    const after = inlineMarkup(translated);

    return after.elements.length === before.elements.length
        && after.elements.every((element, index) => element === before.elements[index])
        && [...after.texts].every((key) => before.texts.has(key));
}

async function translateBlock(editor, block, detail) {
    const texts = [...block.getChildren()].filter((child) => child.is('$text'));
    if (!detail.translatable(texts.map((text) => text.data).join(''))) {
        return null;
    }
    if (!texts.some((text) => text.hasAttribute('code'))) {
        const html = editor.data.stringify(block);
        const translated = await detail.translate(html);
        if (translated === html) {
            return null;
        }
        const fragment = editor.data.parse(translated, block);
        if (sameMarkup(block, fragment)) {
            return (writer) => {
                writer.remove(writer.createRangeIn(block));
                writer.insert(fragment, block, 0);
            };
        }
    }
    // The text nodes one by one, by their offsets in the block: from the last to the first, a replaced text changes
    // nothing before it (removing a text can merge its neighbours, so nodes are not kept).
    const parts = texts.filter((text) => !text.hasAttribute('code')).map((text) => ({
        offset: text.startOffset,
        size: text.offsetSize,
        attributes: new Map(text.getAttributes()),
        data: text.data,
    }));
    for (const part of parts) {
        part.text = await detail.translate(part.data);
    }

    return (writer) => {
        for (const part of [...parts].reverse()) {
            if (part.text === part.data || part.text === '') {
                continue;
            }
            const start = writer.createPositionAt(block, part.offset);
            writer.remove(writer.createRange(start, start.getShiftedBy(part.size)));
            writer.insertText(part.text, part.attributes, start);
        }
    };
}

async function translateTexts(editor, detail) {
    editor.enableReadOnlyMode(TRANSLATION_LOCK);
    let locked = true;
    const release = () => {
        if (locked) {
            locked = false;
            editor.disableReadOnlyMode(TRANSLATION_LOCK);
        }
    };
    const changes = [];
    try {
        const blocks = [];
        for (const item of editor.model.createRangeIn(editor.model.document.getRoot()).getItems()) {
            if (item.is('element') && item.name !== 'codeBlock' && [...item.getChildren()].some((child) => child.is('$text'))) {
                blocks.push(item);
            }
        }
        for (const block of blocks) {
            const change = await translateBlock(editor, block, detail);
            if (change !== null) {
                changes.push(change);
            }
        }
    } catch (error) {
        release();
        throw error;
    }

    let batch = null;
    const apply = () => {
        release();
        if (changes.length === 0) {
            return;
        }
        editor.model.change((writer) => {
            batch = writer.batch;
            for (const change of changes) {
                change(writer);
            }
        });
    };
    const revert = () => {
        if (batch !== null && editor.commands.get('undo') !== undefined) {
            editor.execute('undo', batch);
        }
    };

    return { apply, revert, release };
}

for (const textarea of document.querySelectorAll('textarea[data-rich-text]')) {
    const started = start(textarea);
    started.catch((error) => {
        // The textarea stays as it is: a plain HTML field.
        console.error('The rich-text editor could not start', error);
    });
    // translate.js asks for the translated content; an editor that did not start rejects (translate.js reports it).
    textarea.addEventListener('rich-text-translate', (event) => {
        event.detail.pending.push(started.then((editor) => translateTexts(editor, event.detail)));
    });
}
