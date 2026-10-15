<?php
/**
 * One level of the tree editor, recursive: renders $nodes as an <ol data-tree-list> whose <li> items
 * (tree.js's drag targets) each carry a fixed id, a handle, a label/meta/hidden chip from $items, the four
 * arrow-button moves in one segmented control, and their own nested (possibly empty) list so drag-and-drop can
 * give them children. A pinned item (the main menu's fixed items on the Sections screen) only moves up and down
 * (its outdent and indent are locked buttons that post nothing, so its moves line up with the other rows; their
 * reason is "locked" outdent/indent) and has no list: tree.js keeps it at the top level (data-tree-pinned). An item
 * with "actions" (the Sections and menu screens) ends its row with three fixed slots, Add inside, Edit and Delete: a URL is an
 * icon link (data-dialog-open: dialog.js opens it in the screen's dialog, else it is a full page), a null URL with a
 * "locked" reason (a translation key) a locked button, and a null URL without one an empty slot of the same width, so
 * every row's buttons line up in columns. A locked button is aria-disabled (no HTML attribute): it stays in the Tab order
 * and does nothing, its reason is its title (shown as a tip on keyboard focus, admin.css) and its description (a
 * visually hidden span). The moves and the actions share .admin-tree-controls, which wraps as one group. Every item but a
 * pinned one ends its label line with the accordion's toggle (tree.js): a text button, "2 subsections" ($countKey) and
 * a chevron, that shows or hides the item's nested list (its id is the button's aria-controls); its visible text is its
 * name. It is pushed to the end of the label line, so on a wide screen it sits right before the moves (which keep
 * their columns) and on a phone, where the controls wrap below, opposite the name; it is drawn
 * only while the item has children and, like the handle, only with JavaScript (tree.js also toggles an item when its
 * row is clicked outside its controls and link). "Add inside" (data-tree-opens) opens its item, so the new child shows
 * when the screen comes back. The Sections screen's rows start with a fourth slot, "Order articles" (its item's "actions"
 * has an "order" key; a full page, not the dialog). A $flat list (the articles of a section) has items that only move up
 * and down: no outdent or indent, no toggle and no nested lists. An item's "status" (an EntryStatus) is a chip like the
 * entries list's.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Content\TreeNode> $nodes
 * @var string $countKey the ICU message of an item's number of children
 * @var string $addKey the ICU message naming an item's "Add inside" ({label})
 * @var string|null $listId (optional) the id of this nested list, named by its item's toggle
 * @var bool $flat (optional) a list without nesting
 * @var array<int, array{label: string, url: string|null, meta: string, hidden: bool, status?: AtarCms\Content\EntryStatus, pinned?: bool, actions?: array{order?: string|null, add: string|null, edit: string|null, delete: string|null}, locked?: array<string, string>}> $items id => how to render it
 */
$flat ??= false;
$moves = $flat ? ['up' => 'arrow-up', 'down' => 'arrow-down'] : ['up' => 'arrow-up', 'down' => 'arrow-down', 'outdent' => 'arrow-left', 'indent' => 'arrow-right'];
$actions = [
    'order' => ['arrow-down-wide-narrow', 'admin.sections.order_articles', 'admin-row-action'],
    'add' => ['plus', $addKey, 'admin-row-action'],
    'edit' => ['pencil', 'admin.tree.action.edit', 'admin-row-action'],
    'delete' => ['trash-2', 'admin.tree.action.delete', 'admin-row-action admin-row-action-danger'],
];
$listId ??= null;
?>
<ol class="admin-tree" data-tree-list<?= $listId === null ? '' : ' id="' . $this->e($listId) . '"' ?>>
<?php foreach ($nodes as $node): ?>
<?php $item = $items[$node->id] ?? ['label' => '#' . $node->id, 'url' => null, 'meta' => '', 'hidden' => false]; ?>
<?php $pinned = $item['pinned'] ?? false; ?>
<?php $children = 'tree-' . $node->id . '-items'; ?>
<li class="admin-tree-item" data-id="<?= $this->e($node->id) ?>"<?= $pinned ? ' data-tree-pinned' : '' ?>>
    <div class="admin-tree-row">
        <span class="admin-tree-handle" data-tree-handle title="<?= $this->e($this->t('admin.tree.drag')) ?>"><?= $this->icon('grip-vertical') ?></span>
        <span class="admin-tree-text">
            <?php if ($item['url'] !== null): ?>
            <a class="admin-tree-label" href="<?= $this->e($item['url']) ?>"><?= $this->e($item['label']) ?></a>
            <?php else: ?>
            <span class="admin-tree-label"><?= $this->e($item['label']) ?></span>
            <?php endif ?>
            <?php if ($item['meta'] !== ''): ?>
            <span class="admin-muted admin-tree-meta"><?= $this->e($item['meta']) ?></span>
            <?php endif ?>
            <?php if ($item['hidden']): ?>
            <span class="admin-chip admin-chip-draft"><?= $this->e($this->t('admin.tree.hidden')) ?></span>
            <?php endif ?>
            <?php if (isset($item['status'])): ?>
            <span class="admin-chip admin-chip-<?= $this->e($item['status']->value) ?>"><?= $this->e($this->t('admin.entries.status.' . $item['status']->value)) ?></span>
            <?php endif ?>
            <?php if (!$pinned && !$flat): ?>
            <button type="button" class="admin-tree-toggle" data-tree-toggle aria-expanded="false" aria-controls="<?= $this->e($children) ?>"><span data-tree-count><?= $this->e($this->t($countKey, ['count' => count($node->children)])) ?></span><?= $this->icon('chevron-right') ?></button>
            <?php endif ?>
        </span>
        <span class="admin-tree-controls">
            <span class="admin-segmented admin-tree-moves" role="group" aria-label="<?= $this->e($this->t('admin.tree.moves')) ?>">
                <?php foreach ($moves as $move => $icon): ?>
                <?php $hint = $this->t('admin.tree.move.' . $move, ['label' => $item['label']]); ?>
                <?php if ($pinned && ($move === 'outdent' || $move === 'indent')): ?>
                <?php $reason = isset($item['locked'][$move]) ? $this->t($item['locked'][$move], ['label' => $item['label']]) : $hint; ?>
                <?php $reasonId = 'tree-' . $node->id . '-' . $move . '-reason'; ?>
                <button type="button" class="admin-locked" aria-disabled="true" title="<?= $this->e($reason) ?>" aria-label="<?= $this->e($hint) ?>" aria-describedby="<?= $this->e($reasonId) ?>"><?= $this->icon($icon) ?></button><span class="admin-visually-hidden" id="<?= $this->e($reasonId) ?>"><?= $this->e($reason) ?></span>
                <?php else: ?>
                <button type="submit" name="move" value="<?= $this->e($node->id . ':' . $move) ?>" title="<?= $this->e($hint) ?>" aria-label="<?= $this->e($hint) ?>"><?= $this->icon($icon) ?></button>
                <?php endif ?>
                <?php endforeach ?>
            </span>
            <?php if (isset($item['actions'])): ?>
            <span class="admin-row-actions">
                <?php foreach ($actions as $action => [$icon, $key, $class]): ?>
                <?php if (!array_key_exists($action, $item['actions'])) { continue; } ?>
                <?php $name = $this->t($key, ['label' => $item['label']]); ?>
                <?php $reason = $item['locked'][$action] ?? null; ?>
                <?php if ($item['actions'][$action] !== null): ?>
                <a class="<?= $class ?>" href="<?= $this->e($item['actions'][$action]) ?>" title="<?= $this->e($name) ?>" aria-label="<?= $this->e($name) ?>"<?= $action === 'order' ? '' : ' data-dialog-open' ?><?= $action === 'add' ? ' data-tree-opens' : '' ?>><?= $this->icon($icon) ?></a>
                <?php elseif ($reason !== null): ?>
                <?php $reasonText = $this->t($reason, ['label' => $item['label']]); ?>
                <?php $reasonId = 'tree-' . $node->id . '-' . $action . '-reason'; ?>
                <button type="button" class="<?= $class ?> admin-locked" aria-disabled="true" title="<?= $this->e($reasonText) ?>" aria-label="<?= $this->e($name) ?>" aria-describedby="<?= $this->e($reasonId) ?>"><?= $this->icon($icon) ?></button><span class="admin-visually-hidden" id="<?= $this->e($reasonId) ?>"><?= $this->e($reasonText) ?></span>
                <?php else: ?>
                <span class="admin-row-slot"></span>
                <?php endif ?>
                <?php endforeach ?>
            </span>
            <?php endif ?>
        </span>
    </div>
    <?php if (!$pinned && !$flat): ?>
    <?= $this->partial('tree/nodes', ['nodes' => $node->children, 'items' => $items, 'countKey' => $countKey, 'addKey' => $addKey, 'listId' => $children]) ?>
    <?php endif ?>
</li>
<?php endforeach ?>
</ol>
