<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The url of a video (<oembed url>) is stored in its canonical form (VideoUrl::canonical()); anything that is not a
 * supported YouTube or Vimeo link loses the attribute, and such an <oembed> renders as nothing.
 */
final class OembedUrlSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): array
    {
        return ['oembed'];
    }

    public function getSupportedAttributes(): array
    {
        return ['url'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return VideoUrl::parse($value)?->canonical();
    }
}
