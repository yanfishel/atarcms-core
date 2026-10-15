<?php
/**
 * The languages of a translation group in a list's Languages cell (entries, terms): each the theme's round flag
 * (languageFlag(), named in itself as alt and title), else its code as an <abbr>.
 *
 * @var AtarCms\View\Template $this
 * @var list<string> $codes
 */
?>
<span class="admin-flags"><?php foreach ($codes as $code): ?><?php $flag = $this->languageFlag($code); $language = $this->languageName($code); ?><?php if ($flag !== null): ?><img class="admin-flag" src="<?= $this->e($flag) ?>" alt="<?= $this->e($language) ?>" title="<?= $this->e($language) ?>" width="20" height="20"><?php else: ?><abbr class="admin-flag-code" title="<?= $this->e($language) ?>"><?= $this->e(strtoupper($code)) ?></abbr><?php endif ?><?php endforeach ?></span>
