<?php

declare(strict_types=1);

return [
    'csp' => [
        // Extra sources for iframes. The rich-text video players (https://www.youtube-nocookie.com and
        // https://player.vimeo.com) and the Google map of the contacts page (https://www.google.com) are
        // always allowed.
        'frame_src' => [],
        // Extra sources for images, e.g. Google Ads' country domains beyond the built-in ones
        // (https://www.google.com.ua: www.google.<TLD> of the visitors' country).
        'img_src' => [],
        // Extra sources for scripts' requests (fetch, beacons), e.g. the same Google country domains.
        'connect_src' => [],
    ],
];
