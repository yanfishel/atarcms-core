<?php
/**
 * The admin brand's mark (the sidebar and the login card): the site icon when the settings choose one, else the
 * AtarCMS logo in the admin colour. The name always stands beside it, so the image is decorative.
 *
 * @var AtarCms\View\Template $this
 */
$brand = $this->adminBrand();
?>
<img class="admin-brand-mark<?= $brand->siteIcon ? ' admin-brand-icon' : '' ?>" src="<?= $this->e($brand->markUrl) ?>" alt="" width="36" height="36">
