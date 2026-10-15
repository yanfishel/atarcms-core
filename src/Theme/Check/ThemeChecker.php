<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

use AtarCms\Content\Collections;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\TextDirection;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\ConfigException;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\View\ThemeConfig;
use AtarCms\View\ThemeDirection;
use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use MessageFormatter;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The static part of theme:check: reads a theme directory against the site's content model (collections, taxonomies,
 * languages, routes) and reports what would break a page, the Content Security Policy or right-to-left layout. It
 * writes nothing and needs no database; RenderSmoke renders the pages.
 */
final class ThemeChecker
{
    /** Template helpers whose single-quoted first argument names something the site must have. */
    private const string HELPER_CALL = '/\$this->(menu|img|mediaUrl|url|asset|t)\(/';

    private const string NETWORK = '(?:https?:)?\/\/[^"\'\s)]+';

    public function __construct(
        private readonly Collections $collections,
        private readonly Locales $locales,
        private readonly Router $router,
        private readonly MainMenu $mainMenu,
        private readonly FormDefinitions $forms,
    ) {}

    public function check(string $directory): Report
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $report = new Report(basename($directory));
        $label = 'themes/' . basename($directory);
        $theme = $this->config($directory, $label, $report);
        $this->templates($directory, $label, $report);
        $this->sources($directory, $label, $theme, $report);
        $this->catalogs($directory, $label, $report);
        $this->styles($directory, $label, $theme, $report);
        $this->scripts($directory, $label, $report);
        $this->vendor($directory, $label, $report);

        return $report;
    }

    private function config(string $directory, string $label, Report $report): ?ThemeConfig
    {
        if (!is_file($directory . '/theme.php')) {
            $report->warning('config', $label, null, 'No theme.php: the theme has no menu locations and no image sizes');
        }
        try {
            $theme = ThemeConfig::load($directory);
        } catch (ConfigException $e) {
            $report->error('config', $label . '/theme.php', null, $e->getMessage());

            return null;
        }
        $rtl = array_values(array_filter($this->locales->all(), static fn(string $l): bool => TextDirection::of($l) === 'rtl'));
        if ($theme->direction === ThemeDirection::Ltr && $rtl !== []) {
            $report->error('config', $label . '/theme.php', null, sprintf(
                'The theme is laid out left to right only ("direction" => "ltr"), but the site has right-to-left languages: %s. Make the CSS logical (margin-inline-start…) and remove "direction".',
                implode(', ', $rtl),
            ));
        }
        if ($this->mainMenu->enabled() && !$theme->hasMenu(MainMenu::LOCATION)) {
            $report->warning('config', $label . '/theme.php', null, 'content.main_menu is set, but theme.php "menus" has no "main" location for it');
        }

        return $theme;
    }

    private function templates(string $directory, string $label, Report $report): void
    {
        $has = static fn(string ...$names): bool => array_any($names, static fn(string $name): bool => is_file($directory . '/' . $name . '.php'));
        foreach ($this->collections->all() as $name => $collection) {
            if ($collection->route !== null && !$has('collections/' . $name . '/show', 'show')) {
                $report->error('templates', $label, null, sprintf('The entries of "%s" need collections/%1$s/show.php or show.php', $name));
            }
            if ($collection->archive !== null && !$has('collections/' . $name . '/index', 'index')) {
                $report->error('templates', $label, null, sprintf('The archive of "%s" needs collections/%1$s/index.php or index.php', $name));
            }
        }
        foreach ($this->collections->taxonomies->all() as $name => $taxonomy) {
            if ($taxonomy->route !== null && !$has('taxonomies/' . $name . '/show', 'term')) {
                $report->error('templates', $label, null, sprintf('The terms of "%s" need taxonomies/%1$s/show.php or term.php', $name));
            }
        }
        if (!$has('errors/404', 'errors/error')) {
            $report->warning('templates', $label, null, 'No errors/404.php or errors/error.php: error pages are the plain core page');
        }
        if ($this->collections->searchable() !== [] && !$has('search')) {
            $report->warning('templates', $label, null, 'No search.php: /search answers 404');
        }
        $pages = array_map(static fn(FormDefinition $form): string => $form->key, array_filter($this->forms->all(), static fn(FormDefinition $form): bool => !$form->isContact()));
        if ($pages !== [] && !$has('form')) {
            $report->error('templates', $label, null, sprintf('No form.php: the forms of config/forms.php (%s) answer 404 at /forms/{key}', implode(', ', $pages)));
        }
        foreach (['collections' => 'a collection of config/collections.php', 'taxonomies' => 'a taxonomy of config/taxonomies.php'] as $kind => $what) {
            foreach (glob($directory . '/' . $kind . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
                $name = basename($folder);
                $known = $kind === 'collections' ? $this->collections->find($name) : $this->collections->taxonomies->find($name);
                if ($known === null) {
                    $report->warning('templates', $label . '/' . $kind . '/' . $name, null, sprintf('"%s" is not %s', $name, $what));
                }
            }
        }
    }

    private function sources(string $directory, string $label, ?ThemeConfig $theme, Report $report): void
    {
        $translator = new Translator($this->locales, [dirname(__DIR__, 3) . '/resources/lang', $directory . '/lang'], new NullLogger());
        foreach ($this->files($directory, ['php', 'html']) as $relative) {
            if (str_starts_with($relative, 'lang/') || $relative === 'theme.php' || str_starts_with($relative, 'assets/')) {
                continue;
            }
            $source = (string) file_get_contents($directory . '/' . $relative);
            $file = $label . '/' . $relative;
            $found = [];
            $add = static function (bool $error, int $offset, string $message) use (&$found, $source): void {
                $found[] = [self::line($source, $offset), $error, $message];
            };
            foreach ($this->sourceRules($source) as [$error, $offset, $message]) {
                $add($error, $offset, $message);
            }
            if (str_ends_with($relative, '.php')) {
                foreach ($this->literals($source, $directory, $theme, $translator) as [$offset, $message]) {
                    $add(true, $offset, $message);
                }
                foreach (self::unescaped($source) as [$offset, $expression]) {
                    $add(false, $offset, sprintf('Printed without escaping: <?= %s ?> (use $this->e())', $expression));
                }
            }
            usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($found as [$line, $error, $message]) {
                $error ? $report->error('sources', $file, $line, $message) : $report->warning('sources', $file, $line, $message);
            }
        }
    }

    /**
     * @return list<array{0: bool, 1: int, 2: string}> [is error, offset, message]
     */
    private function sourceRules(string $source): array
    {
        $found = [];
        // The markup with every PHP block blanked (same length, line breaks kept): tag rules never cross into PHP.
        $markup = self::withoutPhp($source);
        /** @param Closure(string): ?string $message the first group of the match => the message, null for none */
        $each = static function (string $pattern, Closure $message, bool $error = true, bool $raw = false) use ($source, $markup, &$found): void {
            if (preg_match_all($pattern, $raw ? $source : $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                $found[] = [true, 0, sprintf('The file could not be scanned (%s)', preg_last_error_msg())];

                return;
            }
            foreach ($matches as $match) {
                $text = $message(isset($match[1]) ? $match[1][0] : '');
                if (is_string($text)) {
                    $found[] = [$error, $match[0][1], $text];
                }
            }
        };
        $each('/declare\s*\(\s*strict_types/', static fn(): string => 'Templates are plain PHP without declare(strict_types=1)', false, true);
        $each('/\sstyle\s*=/i', static fn(): string => 'A style attribute breaks the CSP (style-src \'self\'): use a class in the stylesheet');
        $each('/<style\b/i', static fn(): string => 'A <style> element breaks the CSP (style-src \'self\'): move the rules to the stylesheet');
        $each('/<script\b([^>]*)>/i', static function (string $attributes): ?string {
            if (preg_match('/\s(?:src|nonce)\s*=/i', $attributes) === 1 || preg_match('/\stype\s*=\s*["\']application\/(?:ld\+)?json/i', $attributes) === 1) {
                return null;
            }

            return 'An inline <script> without the nonce breaks the CSP: move it to assets/js/ or add nonce="<?= $this->e($this->nonce()) ?>"';
        });
        $each('/\s(on[a-z]{3,})\s*=\s*["\']/i', static fn(string $handler): string => sprintf('An event handler attribute (%s) breaks the CSP: attach the listener in a script file', strtolower($handler)));
        $each('/["\']\s*javascript:/i', static fn(): string => 'A javascript: URL breaks the CSP');
        $each(
            '/<(?:script|link|img|source|iframe|video|audio|embed|object)\b[^>]*?\s(?:src|href|srcset|poster|data)\s*=\s*["\'](' . self::NETWORK . ')/i',
            static fn(string $url): string => self::remote($url),
        );

        return $found;
    }

    /**
     * Single-quoted names given to helpers that must exist: menu locations, image sizes, routes, assets, translations.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function literals(string $source, string $directory, ?ThemeConfig $theme, Translator $translator): array
    {
        $found = [];
        preg_match_all(self::HELPER_CALL, $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($calls as $call) {
            $helper = $call[1][0];
            $arguments = self::arguments($source, $call[0][1] + strlen($call[0][0]));
            $literal = self::literal($arguments[in_array($helper, ['img', 'mediaUrl'], true) ? 1 : 0] ?? '');
            if ($literal === null) {
                continue;
            }
            $message = match ($helper) {
                'menu' => $theme === null || $theme->hasMenu($literal) ? null : sprintf('Unknown menu location "%s": theme.php "menus" has %s', $literal, implode(', ', array_keys($theme->menus)) ?: 'none'),
                'img', 'mediaUrl' => $theme === null || isset($theme->images[$literal]) ? null : sprintf('Unknown image size "%s": theme.php "images" has %s', $literal, implode(', ', array_keys($theme->images)) ?: 'none'),
                'url' => $this->hasRoute($literal) ? null : sprintf('Unknown route "%s"', $literal),
                'asset' => is_file($directory . '/assets/' . strtok($literal, '?#')) ? null : sprintf('Missing asset assets/%s', $literal),
                default => str_ends_with($literal, '.') || $translator->canTranslate($this->locales->default(), $literal) ? null : sprintf(
                    'Missing translation "%s" in lang/%s.php (the default language) and the core catalog',
                    $literal,
                    $this->locales->default(),
                ),
            };
            if ($message !== null) {
                $found[] = [$call[0][1], $message];
            }
        }

        return $found;
    }

    private function hasRoute(string $name): bool
    {
        try {
            $this->router->named($name);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function catalogs(string $directory, string $label, Report $report): void
    {
        $default = $this->locales->default();
        $catalogs = [];
        foreach ($this->locales->all() as $locale) {
            $file = $directory . '/lang/' . $locale . '.php';
            if (!is_file($file)) {
                $report->error('catalogs', $label . '/lang/' . $locale . '.php', null, 'Missing: the site has the language ' . $locale);

                continue;
            }
            try {
                $messages = (static fn(string $__file): mixed => require $__file)($file);
            } catch (Throwable $e) {
                $report->error('catalogs', $label . '/lang/' . $locale . '.php', null, $e->getMessage());

                continue;
            }
            if (!is_array($messages)) {
                $report->error('catalogs', $label . '/lang/' . $locale . '.php', null, 'A catalog must return an array');

                continue;
            }
            $catalogs[$locale] = self::flatten($messages, '');
        }
        foreach ($catalogs as $locale => $catalog) {
            $file = $label . '/lang/' . $locale . '.php';
            if ($locale !== $default && isset($catalogs[$default])) {
                $missing = array_keys(array_diff_key($catalogs[$default], $catalog));
                $extra = array_keys(array_diff_key($catalog, $catalogs[$default]));
                if ($missing !== []) {
                    $report->error('catalogs', $file, null, sprintf('Keys missing compared to lang/%s.php: %s', $default, implode(', ', $missing)));
                }
                if ($extra !== []) {
                    $report->error('catalogs', $file, null, sprintf('Keys not in lang/%s.php: %s', $default, implode(', ', $extra)));
                }
            }
            foreach ($catalog as $key => $pattern) {
                if (str_contains($pattern, '{') && !self::validIcu($locale, $pattern)) {
                    $report->error('catalogs', $file, null, sprintf('Invalid ICU message "%s"', $key));
                }
            }
            if ($locale === $default) {
                $this->formTexts($catalog, $file, $report);
            }
            // "bin/atar migrate" titles a new preset page by its label in each language: a key missing there would be
            // the title. A label that is not a dotted key is plain text.
            foreach ($this->collections->all() as $collection) {
                foreach ($collection->presets as $preset => $presetLabel) {
                    if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $presetLabel) === 1 && !isset($catalog[$presetLabel])) {
                        $report->error('catalogs', $file, null, sprintf(
                            'Missing "%s", the title of the preset page "%s" of "%s": the page would be titled with the key',
                            $presetLabel,
                            $preset,
                            $collection->name,
                        ));
                    }
                }
            }
        }
    }

    /**
     * The texts each form of config/forms.php takes from the theme (in the default language: the other catalogs have
     * the same keys): its title, its intro, the e-mail's subject, the thanks, the submit button, a label per field and per
     * option. A missing one shows as its key (the e-mail falls back to the core's words).
     *
     * @param array<string, string> $catalog
     */
    private function formTexts(array $catalog, string $file, Report $report): void
    {
        foreach ($this->forms->configured() as $form) {
            $prefix = 'forms.' . $form->key . '.';
            $keys = array_map(static fn(string $text): string => $prefix . $text, ['title', 'intro', 'subject', 'sent', 'submit']);
            foreach ($form->fields as $field) {
                $keys[] = $prefix . $field->name;
                foreach ($field->options as $option) {
                    $keys[] = $prefix . $field->name . '.' . $option;
                }
            }
            $missing = array_values(array_filter($keys, static fn(string $key): bool => !isset($catalog[$key])));
            if ($missing !== []) {
                $report->warning('catalogs', $file, null, sprintf('Missing texts of the form "%s": %s', $form->key, implode(', ', $missing)));
            }
        }
    }

    private function styles(string $directory, string $label, ?ThemeConfig $theme, Report $report): void
    {
        $ltr = $theme?->direction === ThemeDirection::Ltr;
        if ($ltr) {
            $report->info('css', $label, '"direction" => "ltr": physical sides are allowed');
        }
        foreach ($this->files($directory . '/assets', ['css']) as $relative) {
            $path = $directory . '/assets/' . $relative;
            $file = $label . '/assets/' . $relative;
            $css = self::withoutComments((string) file_get_contents($path), '~/\*.*?\*/~s');
            $found = [];
            if (
                preg_match_all('/(?:@import\s+(?:url\(\s*)?|url\(\s*)["\']?([^"\')\s]+)/i', $css, $urls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false
                || preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false
            ) {
                $report->error('css', $file, null, sprintf('The file could not be scanned (%s)', preg_last_error_msg()));

                continue;
            }
            foreach ($urls as $url) {
                $target = $url[1][0];
                if (preg_match('/^' . self::NETWORK . '$/', $target) === 1) {
                    $found[] = [self::line($css, $url[0][1]), true, self::remote($target)];
                } elseif (preg_match('/^(?:data:|#|\/)/', $target) !== 1 && !is_file(dirname($path) . '/' . (string) strtok($target, '?#'))) {
                    $found[] = [self::line($css, $url[0][1]), true, 'Missing file ' . $target];
                }
            }
            $vendored = str_starts_with($relative, 'vendor/');
            foreach ($rules as $rule) {
                $selector = trim($rule[1][0]);
                $offset = $rule[2][1];
                foreach (explode(';', $rule[2][0]) as $declaration) {
                    $start = $offset + strspn($declaration, " \t\r\n");
                    $offset += strlen($declaration) + 1;
                    if (!str_contains($declaration, ':')) {
                        continue;
                    }
                    [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                    $property = strtolower($property);
                    if (!$ltr && !$vendored && LogicalCss::isPhysical($property, $value) && preg_match(LogicalCss::EDITOR_SIDES, $selector) !== 1) {
                        $found[] = [self::line($css, $start), true, sprintf(
                            '%s: %s names a physical side: use the logical property (margin-inline-start, inset-inline-end, text-align: start…)',
                            $property,
                            $value,
                        )];
                    }
                    if (!$ltr && !$vendored && LogicalCss::isFlip($property, $value) && !str_contains($selector, ':dir(rtl)')) {
                        $found[] = [self::line($css, $start), false, 'A mirroring transform outside a :dir(rtl) rule'];
                    }
                }
            }
            usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($found as [$line, $error, $message]) {
                $error ? $report->error('css', $file, $line, $message) : $report->warning('css', $file, $line, $message);
            }
        }
    }

    private function scripts(string $directory, string $label, Report $report): void
    {
        foreach ($this->files($directory . '/assets', ['js', 'mjs']) as $relative) {
            $js = self::withoutComments((string) file_get_contents($directory . '/assets/' . $relative), '~/\*.*?\*/|^[ \t]*//[^\n]*~ms');
            $file = $label . '/assets/' . $relative;
            $url = '["\']((?:https?:|wss?:)?\/\/[^"\'\s]+)';
            $scanned = preg_match_all('/(?:\bfrom|\bimport\s*\(?|\bimportScripts\s*\()\s*["\'](' . self::NETWORK . ')/', $js, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
                !== false
                && preg_match_all('/(?:\bfetch|\bnew\s+(?:WebSocket|EventSource))\s*\(\s*' . $url . '/', $js, $requests, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false
                // XMLHttpRequest's open(method, url); window.open() is navigation, not a request.
                && preg_match_all(str_contains($js, 'XMLHttpRequest') ? '/\.open\s*\(\s*["\'][A-Za-z]+["\']\s*,\s*' . $url . '/' : '/(?!)/', $js, $xhr, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false;
            if (!$scanned) {
                $report->error('scripts', $file, null, sprintf('The file could not be scanned (%s)', preg_last_error_msg()));

                continue;
            }
            $requests = [...$requests, ...$xhr];
            $found = [];
            foreach ($imports as $match) {
                $found[] = [self::line($js, $match[0][1]), self::remote($match[1][0])];
            }
            foreach ($requests as $match) {
                $found[] = [self::line($js, $match[0][1]), sprintf('A request to another host (%s) breaks the CSP (connect-src \'self\')', $match[1][0] ?? '')];
            }
            usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($found as [$line, $message]) {
                $report->error('scripts', $file, $line, $message);
            }
        }
    }

    private function vendor(string $directory, string $label, Report $report): void
    {
        $root = $directory . '/assets/vendor';
        if (!is_dir($root)) {
            return;
        }
        $folders = [];
        foreach ($this->files($root, null) as $relative) {
            $folders[dirname($relative)] = true;
        }
        foreach (array_keys($folders) as $folder) {
            $licensed = false;
            for ($current = $folder; ; $current = dirname($current)) {
                foreach (glob($root . '/' . ($current === '.' ? '' : $current . '/') . '*') ?: [] as $candidate) {
                    if (is_file($candidate) && preg_match('/licen[cs]e|copying|^ofl\b|notice/i', basename($candidate)) === 1) {
                        $licensed = true;
                    }
                }
                if ($licensed || $current === '.') {
                    break;
                }
            }
            if (!$licensed) {
                $report->warning('vendor', $label . '/assets/vendor' . ($folder === '.' ? '' : '/' . $folder), null, 'No licence file (LICENSE, COPYING, OFL.txt…) next to the vendored files');
            }
        }
    }

    /**
     * The files under $directory with one of $extensions (null: every file), relative, sorted.
     *
     * @param list<string>|null $extensions
     *
     * @return list<string>
     */
    private function files(string $directory, ?array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && ($extensions === null || in_array(strtolower($file->getExtension()), $extensions, true))) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($directory) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The arguments of the call whose "(" ends at $offset, split at top-level commas (strings and brackets kept whole).
     *
     * @return list<string>
     */
    private static function arguments(string $source, int $offset): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = null;
        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                $current .= $character;
                if ($character === '\\') {
                    $current .= $source[++$i] ?? '';
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '\'' || $character === '"') {
                $quote = $character;
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                --$depth;
            } elseif ($character === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $character;
        }
        $arguments[] = trim($current);

        return $arguments;
    }

    private static function literal(string $argument): ?string
    {
        return preg_match('/^\'([^\'\\\\]*)\'$/', $argument, $match) === 1 ? $match[1] : null;
    }

    /**
     * Short echo tags that print a property chain or an array item as it is ($entry->title: user text, unescaped). A
     * bare variable is left alone: templates pass rendered HTML around in variables ($aside).
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function unescaped(string $source): array
    {
        preg_match_all('/<\?=\s*(.*?)\s*;?\s*\?>/s', $source, $echoes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($echoes as $echo) {
            $expression = $echo[1][0];
            if ($expression === '$content' || str_ends_with($expression, '->html')) {
                continue;
            }
            if (preg_match('/^\$(?!this\b)[A-Za-z_]\w*(?:->\w+(?:\([^()]*\))?|\[[^\]]*\])+$/', $expression) === 1) {
                $found[] = [$echo[0][1], $expression];
            }
        }

        return $found;
    }

    private static function remote(string $url): string
    {
        return sprintf('A file loaded from another host (%s) breaks the CSP: vendor it into assets/', $url);
    }

    private static function line(string $text, int $offset): int
    {
        return substr_count(substr($text, 0, $offset), "\n") + 1;
    }

    /**
     * $source with every PHP block (<?php … ?>, <?= … ?>, an unclosed one to the end) replaced by spaces, line breaks
     * kept: same byte offsets and lines, no PHP left for the markup rules to trip over.
     */
    private static function withoutPhp(string $source): string
    {
        return (string) preg_replace_callback(
            '/<\?.*?(?:\?>|\z)/s',
            static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $source,
        );
    }

    /**
     * $text with the matches of $pattern blanked, their line breaks kept so that line numbers stay right.
     */
    private static function withoutComments(string $text, string $pattern): string
    {
        return (string) preg_replace_callback($pattern, static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $text);
    }

    private static function validIcu(string $locale, string $pattern): bool
    {
        try {
            new MessageFormatter($locale, $pattern);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $flat;
    }
}
