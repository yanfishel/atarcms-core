<?php
/**
 * The item of an activity history row, escaped, as "Kind: “name”" (AuditSubject) or "Kind #id"; a kind without a
 * translation (a module's) stays as recorded; nothing without an item. Shared by the history screen and the
 * dashboard's feed.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Auth\AuditEntry $entry
 */
if ($entry->subjectType === null) {
    return;
}
$kindKey = 'admin.audit.subject.' . $entry->subjectType;
$kind = $this->hasTranslation($kindKey) ? $this->t($kindKey) : $entry->subjectType;
$name = AtarCms\Admin\AuditSubject::name($entry);
echo $this->e($name !== null
    ? $this->t('admin.audit.subject_named', ['kind' => $kind, 'name' => $name])
    : $this->t('admin.audit.subject_id', ['kind' => $kind, 'id' => (string) $entry->subjectId]));
