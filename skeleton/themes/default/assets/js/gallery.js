// The gallery lightbox: PhotoSwipe 5.4.4 (MIT, vendor/photoswipe.LICENSE) opens each .gallery link's large image over
// the page, with keyboard, swipe and zoom. The links carry the image's size (data-pswp-width/-height); the full
// PhotoSwipe module loads on the first open. Without JavaScript the links open the large image.

import PhotoSwipeLightbox from '../vendor/photoswipe-lightbox-5.4.4.esm.min.js';

const labels = document.querySelector('[data-lightbox-labels]')?.dataset ?? {};

for (const gallery of document.querySelectorAll('.gallery')) {
    const lightbox = new PhotoSwipeLightbox({
        gallery,
        children: 'a[data-pswp-width]',
        pswpModule: () => import('../vendor/photoswipe-5.4.4.esm.min.js'),
        closeTitle: labels.close,
        zoomTitle: labels.zoom,
        arrowPrevTitle: labels.previous,
        arrowNextTitle: labels.next,
        errorMsg: labels.error,
    });
    lightbox.init();
}
