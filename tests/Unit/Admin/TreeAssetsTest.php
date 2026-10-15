<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class TreeAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    public function testSortableIsVendoredWithItsLicense(): void
    {
        $sortable = (string) file_get_contents(self::ASSETS . '/vendor/sortable-1.15.7.esm.js');
        $license = (string) file_get_contents(self::ASSETS . '/vendor/sortable.LICENSE');

        self::assertStringContainsString('Sortable 1.15.7', substr($sortable, 0, 200));
        self::assertStringContainsString('export default Sortable;', $sortable);
        self::assertStringStartsWith('SortableJS 1.15.7 (https://github.com/SortableJS/Sortable), vendored for the AtarCMS admin.', $license);
        self::assertStringContainsString('MIT License', $license);
    }

    public function testTheTreeModuleImportsTheVendoredBuildAndNeverInjectsMarkupOrStyles(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');

        self::assertStringContainsString("import Sortable from './vendor/sortable-1.15.7.esm.js';", $tree);
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $tree, $forbidden);
        }
    }

    /**
     * An arrow button that would change nothing is disabled, after the page loads and after every drop: up for the
     * first item of a list, down for the last, outdent at the top level, indent for a first item or past the depth.
     * The server renders them enabled (it cannot know the order after a drag); without JavaScript a useless move
     * changes nothing.
     */
    public function testTheModuleDisablesTheMovesThatWouldChangeNothing(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');
        $nodes = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/tree/nodes.php');

        self::assertStringContainsString("button[name=\"move\"]", $tree);
        self::assertStringContainsString('name="move"', $nodes);
        self::assertStringNotContainsString(' disabled', $nodes, 'the server disables no button: every move is enabled, a locked one is aria-disabled');
        self::assertStringContainsString('.disabled = ', $tree, 'through the property');
        foreach (["up: index === 0", "down: index === siblings.length - 1", "outdent: depth === 0", "indent: index === 0 || depth + 1 + height(item) > maxDepth"] as $rule) {
            self::assertStringContainsString($rule, $tree, $rule);
        }
        self::assertMatchesRegularExpression('/onSort: \(\) => \{\s*update\(\);\s*recount\(\);\s*syncMoves\(\);\s*schedule\(\);\s*\}/', $tree, 'after every drop');
        self::assertStringContainsString("const pinned = (item) => item !== undefined && item.matches('[data-tree-pinned]');", $tree);
        self::assertStringContainsString('|| pinned(siblings[index - 1]),', $tree, 'nothing moves into a pinned item');
        self::assertStringContainsString('&& (!pinned(event.dragged) || event.to === root);', $tree, 'a pinned item stays at the top level');
        self::assertStringContainsString("    });\n    syncMoves();\n    form.addEventListener('click',", $tree, 'and once on load');
        self::assertStringContainsString(
            '.admin-content .admin-segmented button:disabled { background: var(--raised); color: var(--border-strong); cursor: default; }',
            (string) file_get_contents(self::ASSETS . '/admin.css'),
            'a disabled move looks disabled, also under the pointer (the rule comes after :hover)',
        );
    }

    /**
     * The tree is an accordion: every item starts collapsed (the stylesheet hides the children under .js, so the first
     * paint is already collapsed), its chevron toggles the admin-tree-open class and aria-expanded (properties, no
     * setAttribute), and the open items are remembered per screen and language in localStorage, every access wrapped
     * in try/catch.
     * During a drag a collapsed item opens after the pointer rests on it (leaving it, also for empty space, stops the
     * timer), and the item a drop lands in (an arrow's indent included) is opened. The hidden children stay in the DOM, so the tree JSON and a dragged item keep them.
     */
    public function testTheTreeIsAnAccordion(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');

        foreach ([
            "const OPEN_KEY = 'admin.tree.open:';",
            'const OPEN_DELAY = 600;',
            "const storageKey = OPEN_KEY + form.getAttribute('action') + ':' + form.elements.namedItem('locale')?.value;",
            "try {\n            localStorage.setItem(storageKey, JSON.stringify([...form.querySelectorAll('.admin-tree-open')].map((item) => Number(item.dataset.id))));\n        } catch {",
            "item.classList.toggle('admin-tree-open', open);",
            'toggle.ariaExpanded = String(open);',
            "const toggle = target?.closest('[data-tree-toggle]') ?? null;",
            "const adder = target?.closest('[data-tree-opens]') ?? null;",
            "setOpen(item, adder !== null || !item.classList.contains('admin-tree-open'));",
            "const over = event.related?.closest('[data-id]') ?? null;", // an empty list (a drop into a leaf) counts as its item
            "if (item !== null && !item.classList.contains('admin-tree-open') && itemsOf(childList(item)).length > 0) {\n            hoverTimer = setTimeout(() => {",
            "const parent = event.to.closest('[data-id]');",
            "const above = move === 'indent' ? form.querySelector('[data-id=\"' + id + '\"]')?.previousElementSibling : null;",
        ] as $code) {
            self::assertStringContainsString($code, $tree, $code);
        }
        self::assertSame(1, substr_count($tree, 'localStorage.'), 'one write: tree-open.js reads');
        self::assertStringContainsString(
            "form.querySelectorAll('[data-tree-count]').forEach((count) => {\n            const number = itemsOf(childList(count.closest('[data-id]'))).length;\n            const text = counts[plurals.select(number)] ?? counts.other ?? Object.values(counts)[0];\n            if (typeof text === 'string') {\n                count.textContent = text.replace('#', numbers.format(number));\n            }\n        });",
            $tree,
            'a drop updates the number of children on every toggle (text only), in the plural form of the interface language',
        );
        foreach ([
            "counts = JSON.parse(form.dataset.treeCounts ?? '{}');",
            "const lang = document.documentElement.lang || undefined;\n    let plurals;\n    let numbers;\n    try {\n        plurals = new Intl.PluralRules(lang);\n        numbers = new Intl.NumberFormat(lang);\n    } catch {",
            "plurals = new Intl.PluralRules();\n        numbers = new Intl.NumberFormat();", // an invalid language tag never stops the module
        ] as $code) {
            self::assertStringContainsString($code, $tree, 'the server renders the forms, the browser picks one: ' . $code);
        }
        // The row of an item with children toggles it, except on the row's handle, links, buttons and anything else
        // focusable, and except for a click that ends a text selection or a drag.
        foreach ([
            "const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [tabindex], [data-tree-handle]';",
            "const hit = target?.closest('.admin-tree-row') ?? null;",
            // Only what is inside the row counts: the page's <main tabindex="-1"> holds every row.
            "const row = hit !== null && !hit.contains(target.closest(INTERACTIVE)) && !dropping && String(window.getSelection() ?? '') === '' ? hit : null;",
            'const item = (toggle ?? adder ?? row)?.closest(\'[data-id]\') ?? null;',
            'if (item === null || (row !== null && itemsOf(childList(item)).length === 0)) {',
            "dropping = true;\n                setTimeout(() => {\n                    dropping = false;\n                });",
            "const toggle = item.querySelector(':scope > .admin-tree-row [data-tree-toggle]');",
        ] as $code) {
            self::assertStringContainsString($code, $tree, $code);
        }
        self::assertStringContainsString("onChange: () => {\n                recount();\n            },", $tree, 'and so does every move during a drag');
        self::assertStringContainsString('children: serialize(childList(item)),', $tree, 'collapsed children are serialized like the others');
        foreach ([
            "onStart: (event) => {\n                tracking(event.item);\n            },",
            "onEnd: (event) => {\n                tracking(null);\n                hoverOver(null);",
            "['dragover', 'pointermove', 'mousemove', 'touchmove'].forEach((type) => {",
            "document.addEventListener(type, leave, { passive: true });",
            "document.removeEventListener(type, leave);",
            "const point = event.touches?.[0] ?? event;\n        const under = document.elementFromPoint(point.clientX, point.clientY);\n        if (!hovered.querySelector(':scope > .admin-tree-row').contains(under) && !dragged?.contains(under)) {\n            hoverOver(null);\n        }",
        ] as $code) {
            self::assertStringContainsString($code, $tree, 'leaving the hovered item during a drag stops its timer: ' . $code);
        }
    }

    /**
     * The remembered open items are open from the first paint (no layout shift on a cold load, when tree.js runs
     * after the first paint): tree-open.js is a classic script (parser-blocking, not a module, no defer or async)
     * placed in the tree form right before the list, with the page's nonce; its MutationObserver opens each
     * remembered item as the parser inserts it (observer callbacks run before rendering) and stops at
     * DOMContentLoaded. It reads the key tree.js writes, inside try/catch, and like tree.js never injects markup or
     * styles and never calls setAttribute (aria-expanded through the property).
     */
    public function testRememberedItemsOpenBeforeTheFirstPaint(): void
    {
        $open = (string) file_get_contents(self::ASSETS . '/tree-open.js');
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');
        $editor = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/tree/editor.php');

        self::assertStringContainsString(
            "data-tree-input>\n    <script src=\"<?= \$this->e(\$this->asset('tree-open.js')) ?>\" nonce=\"<?= \$this->e(\$this->nonce()) ?>\"></script>\n    <?= \$this->partial('tree/nodes',",
            $editor,
            'a parser-blocking script right before the list',
        );
        foreach (['import ', 'export ', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $open, $forbidden);
        }
        foreach ([
            "const form = document.currentScript?.closest('[data-tree-form]');",
            "const storageKey = 'admin.tree.open:' + form.getAttribute('action') + ':' + form.elements.namedItem('locale')?.value;",
            "try {\n        const stored = JSON.parse(localStorage.getItem(storageKey) ?? '[]');\n        opened = Array.isArray(stored) ? stored.map(String) : [];\n    } catch {",
            "item.classList.add('admin-tree-open');",
            "toggle.ariaExpanded = 'true';",
            "const toggle = item.querySelector(':scope > .admin-tree-row [data-tree-toggle]');",
            "const observer = new MutationObserver(open);\n    observer.observe(form, { childList: true, subtree: true });",
            "document.addEventListener('DOMContentLoaded', () => {\n        observer.disconnect();\n        open();\n    }, { once: true });",
        ] as $code) {
            self::assertStringContainsString($code, $open, $code);
        }
        self::assertSame(1, substr_count($open, 'localStorage.'), 'it only reads');
        self::assertStringContainsString("const OPEN_KEY = 'admin.tree.open:';", $tree, 'the key tree.js writes');
        self::assertStringContainsString("const storageKey = OPEN_KEY + form.getAttribute('action') + ':' + form.elements.namedItem('locale')?.value;", $tree);
        self::assertStringNotContainsString('admin-tree-enabled', $tree, 'nothing of a row waits for tree.js');
    }

    /**
     * A drop is saved in the background a moment later: one request at a time to the form's own action with its
     * fields (the CSRF token included), asking for JSON; the answer's version replaces the form's. The result is a
     * toast (every message is one): a clone of the form's success or failure <template> appended to the page's toast
     * stack, the failure's text set through textContent and its "Reload the page" button wired; each result replaces
     * the form's previous toast. An arrow button pressed during a save waits for it; a pending save is dropped because
     * the arrow's post carries the whole tree.
     */
    public function testTheModuleSavesADropInTheBackground(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');
        $editor = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/tree/editor.php');

        foreach ([
            'const SAVE_DELAY = 500;',
            'timer = setTimeout(save, SAVE_DELAY);',
            "const response = await fetch(form.action, {\n                method: 'POST',\n                body: new FormData(form),\n                headers: { Accept: 'application/json' },\n                credentials: 'same-origin',",
            'version.value = json.version;',
            "const toasts = document.querySelector('[data-toasts]');",
            "shown?.remove();\n        const toast = template.content.firstElementChild.cloneNode(true);",
            "toast.querySelector('.admin-toast-text').textContent = message;",
            "toast.querySelector('[data-toast-action]')?.addEventListener('click', () => {\n            window.location.reload();\n        });",
            "toasts.append(toast);",
            'notify(savedToast, null);',
            "notify(failedToast, typeof json.error === 'string' ? json.error : null);",
            "if (saving) {\n            again = true;\n            return;\n        }",
            "if (saving) {\n            event.preventDefault();\n            submitter = event.submitter;\n        }",
            'form.requestSubmit(button);',
        ] as $code) {
            self::assertStringContainsString($code, $tree, $code);
        }
        self::assertSame(1, substr_count($tree, 'fetch('), 'no other request');
        foreach (['data-tree-status', 'dataset.saved', 'dataset.failed', 'textContent = form.'] as $gone) {
            self::assertStringNotContainsString($gone, $tree, 'no status line: ' . $gone);
        }
        foreach (['data-tree-version', 'template data-tree-saved', 'template data-tree-failed', "'admin.tree.flash.saved'", "'admin.tree.status.failed'", "'action' => \$this->t('admin.tree.reload')"] as $hook) {
            self::assertStringContainsString($hook, $editor, $hook);
        }
        foreach (['admin-action-bar', 'role="status"', 'data-saving'] as $gone) {
            self::assertStringNotContainsString($gone, $editor, 'no status bar: ' . $gone);
        }
        self::assertStringNotContainsString('type="submit"', $editor, 'no "Save order" button: the arrows are the submit buttons');
    }

    /**
     * Each result replaces the previous toast of the form; when that toast held the focus (its "Reload the page"),
     * the new toast's first button takes it, so it does not drop to the page.
     */
    public function testNotifyMovesTheFocusToTheNewToast(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');

        self::assertStringContainsString("const hadFocus = shown?.contains(document.activeElement) ?? false;\n        shown?.remove();", $tree);
        self::assertMatchesRegularExpression("~shown = toast;\\s*if \\(hadFocus\\) \\{\\s*toast\\.querySelector\\('\\[data-toast-action\\], \\[data-toast-close\\]'\\)\\?\\.focus\\(\\);~", $tree);
    }

    /**
     * Resting on an item during a drag opens it only when the dragged item could go into it: neither of them pinned
     * and its children's level within the depth limit for the dragged item's height. The drop check comes first.
     */
    public function testHoverOpensOnlyItemsTheDragCouldEnter(): void
    {
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');

        self::assertStringContainsString(
            "const enterable = (item, dragged) => item !== dragged && !pinned(item) && !pinned(dragged)\n        && childList(item) !== null && depthOf(childList(item), root) + height(dragged) <= maxDepth;",
            $tree,
        );
        self::assertMatchesRegularExpression(
            "~onMove: \\(event\\) => \\{\\s*const allowed = depthOf\\(event\\.to, root\\) \\+ height\\(event\\.dragged\\) <= maxDepth && \\(!pinned\\(event\\.dragged\\) \\|\\| event\\.to === root\\);\\s*(//[^\\n]*\\s*)*const over = event\\.related\\?\\.closest\\('\\[data-id\\]'\\) \\?\\? null;\\s*hoverOver\\(over !== null && enterable\\(over, event\\.dragged\\) \\? over : null\\);\\s*return allowed;~",
            $tree,
        );
    }
}
