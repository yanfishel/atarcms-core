<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Field\VideoUrl;
use AtarCms\Content\RichTextTheme;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Media\MediaService;
use AtarCms\View\EditorClasses;
use AtarCms\View\EditorLanguages;
use AtarCms\View\ThemeConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The settings of the rich-text editor (editor.js) for the current user, as the JSON of the widget's data-editor
 * attribute: the profile of the field ("full", or "minimal" for Field::minimal()), the interface language, the upload
 * endpoint and the media library (both only with "media.upload"), the theme's styles, ready-made blocks, classes,
 * font sizes, fonts, colours and markers, the code block and text part languages, the video players and the
 * translated labels. Its keys avoid "style", so editor.js never reads a ".style" member.
 */
final class EditorConfig
{
    public function __construct(
        private readonly RichTextTheme $richText,
        private readonly ThemeConfig $theme,
        private readonly Translator $translator,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
    ) {}

    /**
     * The font sizes, fonts, colours and markers are null while the theme keeps the editor's own (CKEditor then uses
     * its defaults with its translated titles), and an empty list when the theme turns them off.
     *
     * @return array{profile: string, language: string, upload: array{url: string, failed: string, pending: string}|null, library: bool, classStyles: list<array{name: string, element: string, classes: list<string>}>, templates: list<array{label: string, html: string}>, classes: list<string>, fontSizes: list<array{model: string, title: string, view: array{name: string, classes: string}}>|null, fonts: list<array{title: string, family: string}>|null, colors: list<array{color: string, label: string}>|null, markers: list<array{model: string, class: string, title: string, color: string, type: string}>|null, codeLanguages: list<array{language: string, label: string}>, textLanguages: list<array{languageCode: string, title: string, textDirection?: string}>, video: array{youtube: string, vimeo: string}, linkProtocol: string, labels: array{library: string, templates: string, newTab: string, imageFull: string, formatting: string, insert: string, tools: string, count: string, countOver: string}}
     */
    public function settings(ServerRequestInterface $request, bool $minimal = false): array
    {
        $locale = RequestLocale::of($request)->code;
        $label = fn(string $label): string => $this->translator->canTranslate($locale, $label) ? $this->translator->translate($locale, $label) : $label;
        $canUpload = $this->authorizer->can(CurrentUser::of($request), MediaService::UPLOAD);
        $editor = $this->theme->editor;
        $styles = [];
        foreach ($editor->styles as $style) {
            $styles[] = ['name' => $label($style['label']), 'element' => $style['element'], 'classes' => $style['classes']];
        }
        $templates = [];
        foreach ($this->richText->templates() as $template) {
            $templates[] = ['label' => $label($template['label']), 'html' => $template['html']];
        }
        $fontSizes = null;
        if ($editor->fontSizes !== null) {
            $fontSizes = [];
            foreach ($editor->fontSizes as $name => $size) {
                $fontSizes[] = ['model' => $name, 'title' => $label($size['label']), 'view' => ['name' => 'span', 'classes' => $size['class']]];
            }
        }
        $markers = null;
        if ($editor->markers !== null) {
            $markers = [];
            foreach ($editor->markers as $name => $marker) {
                $markers[] = ['model' => $name, 'class' => $marker['class'], 'title' => $label($marker['label']), 'color' => $marker['color'], 'type' => $marker['type']];
            }
        }
        $codeLanguages = [];
        foreach (EditorClasses::CODE_LANGUAGES as $language => $languageLabel) {
            $codeLanguages[] = [
                'language' => $language,
                'label' => $language === 'plaintext' ? $this->translator->translate($locale, 'admin.editor.plain_text') : $languageLabel,
            ];
        }
        $textLanguages = [];
        foreach (EditorLanguages::ALL as $code => [$title, $direction]) {
            $textLanguages[] = ['languageCode' => $code, 'title' => $title, ...($direction === 'rtl' ? ['textDirection' => 'rtl'] : [])];
        }

        return [
            'profile' => $minimal ? 'minimal' : 'full',
            'language' => $locale,
            'upload' => $canUpload ? [
                'url' => $this->router->url('admin.media.store'),
                'failed' => $this->translator->translate($locale, 'admin.media.upload.failed'),
                'pending' => $this->translator->translate($locale, 'admin.media.upload.pending'),
            ] : null,
            'library' => $canUpload,
            'classStyles' => $styles,
            'templates' => $templates,
            'classes' => $this->richText->themeClasses(),
            'fontSizes' => $fontSizes,
            'fonts' => $editor->fonts === null ? null : array_map(static fn(array $font): array => ['title' => $label($font['label']), 'family' => $font['family']], $editor->fonts),
            'colors' => $editor->colors === null ? null : array_map(static fn(array $color): array => ['color' => $color['color'], 'label' => $label($color['label'])], $editor->colors),
            'markers' => $markers,
            'codeLanguages' => $codeLanguages,
            'textLanguages' => $textLanguages,
            'video' => ['youtube' => VideoUrl::YOUTUBE_EMBED, 'vimeo' => VideoUrl::VIMEO_EMBED],
            'linkProtocol' => 'https://',
            'labels' => [
                'library' => $this->translator->translate($locale, 'admin.editor.media_library'),
                'templates' => $this->translator->translate($locale, 'admin.editor.templates'),
                'newTab' => $this->translator->translate($locale, 'admin.editor.new_tab'),
                'imageFull' => $this->translator->translate($locale, 'admin.editor.image_full'),
                'formatting' => $this->translator->translate($locale, 'admin.editor.group.formatting'),
                'insert' => $this->translator->translate($locale, 'admin.editor.group.insert'),
                'tools' => $this->translator->translate($locale, 'admin.editor.group.tools'),
                // The character counter of a field with ->max() (editor.js fills in #count and #max).
                'count' => $this->translator->translate($locale, 'admin.editor.count'),
                'countOver' => $this->translator->translate($locale, 'admin.editor.count_over'),
            ],
        ];
    }

    public function json(ServerRequestInterface $request, bool $minimal = false): string
    {
        return json_encode($this->settings($request, $minimal), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
