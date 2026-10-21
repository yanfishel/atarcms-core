<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= $this->e($title ?? 'Fixture') ?></title>
    <?php // The site settings read the database: a 5xx error page renders without them. ?>
    <?php $description = ($description ?? null) ?? (($status ?? 200) < 500 ? $this->siteSettings()->metaDescription : null); ?>
    <?php if ($description !== null): ?><meta name="description" content="<?= $this->e($description) ?>"><?php endif ?>
    <?php if (($robots ?? null) !== null): ?><meta name="robots" content="<?= $this->e($robots) ?>"><?php endif ?>
    <?php if (($status ?? 200) < 500): ?><?= $this->siteHead() ?><?php endif ?>
    <?= $this->hreflang() ?>
    <?= $this->canonical() ?>
    <?= $this->markdownAlternate() ?>
    <?= $this->socialMeta($title ?? null, $description ?? null, isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null, 'large') ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset('app.css')) ?>">
    <script nonce="<?= $this->e($this->nonce()) ?>">window.fixture = true;</script>
</head>
<body><?php if (isset($entry) && $entry instanceof AtarCms\Content\Entry && ($editUrl = $this->editUrl($entry)) !== null): ?><a class="edit-link" href="<?= $this->e($editUrl) ?>">Edit</a><?php endif ?><?= $content ?><ol class="page-trail"><?php foreach ($this->pageTrail() as $crumb): ?><li><a href="<?= $this->e($crumb['url']) ?>"><?= $this->e($crumb['name']) ?></a></li><?php endforeach ?></ol></body>
</html>
