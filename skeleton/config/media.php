<?php

declare(strict_types=1);

// Uploads to the media library (/admin/media). Images are re-encoded on upload (camera metadata such as the GPS
// position is removed); PDFs and videos are stored as they are. PDFs are always downloaded, never shown inline;
// videos play on the page (a hero slide's video).
return [
    // Any of image/jpeg, image/png, image/webp, image/gif, application/pdf, video/mp4, video/webm (SVG is not supported).
    'types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'video/webm'],
    // The largest upload in bytes; PHP's upload_max_filesize and nginx's client_max_body_size must allow it too.
    'max_size' => 10 * 1024 * 1024,
    // The largest video in bytes (videos only; everything else keeps max_size). PHP's upload_max_filesize and
    // post_max_size and the web server's body limit (nginx client_max_body_size, Caddy request_body max_size, Apache
    // LimitRequestBody) must allow it too: the shipped configs take 100 MB.
    'max_video_size' => 100 * 1024 * 1024,
    // The largest image in pixels (width × height), checked before decoding: protects memory from "image bombs".
    // Decoding needs roughly max_pixels × 5 bytes of PHP memory_limit (the skeleton's 256M fits the 25 MP default).
    'max_pixels' => 25_000_000,
    // Longer sides are scaled down to this many pixels on upload.
    'max_dimension' => 4096,
    // JPEG and WebP quality of re-encoded images.
    'quality' => 82,
    // Pictures are also offered as AVIF when PHP's GD can write it (bin/atar doctor: gd.avif); false turns that off.
    // 'avif' => false,
];
