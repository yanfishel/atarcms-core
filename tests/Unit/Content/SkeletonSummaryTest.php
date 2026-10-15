<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Field\RichTextType;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton's news summary is a minimal rich-text field (bold, italic, colour, size, paragraphs) of at most 500
 * visible characters, printed as HTML by the news list.
 */
final class SkeletonSummaryTest extends TestCase
{
    private const string SKELETON = __DIR__ . '/../../../skeleton';

    public function testTheNewsSummaryIsMinimalRichText(): void
    {
        $collections = require self::SKELETON . '/config/collections.php';
        self::assertIsArray($collections);
        $news = array_values(array_filter($collections, static fn(mixed $collection): bool => $collection instanceof Collection && $collection->name === 'news'))[0] ?? null;
        self::assertInstanceOf(Collection::class, $news);
        $summary = $news->fields['summary'];

        self::assertInstanceOf(RichTextType::class, $summary->type);
        self::assertTrue($summary->minimal);
        self::assertSame(500, $summary->max);
        self::assertSame('site.fields.summary', $summary->label);
    }

    public function testTheCardsPrintTheSummaryAsHtml(): void
    {
        $template = (string) file_get_contents(self::SKELETON . '/themes/default/partials/card.php');

        self::assertStringContainsString("\$summary = \$item->collection->field('summary') !== null ? \$item->field('summary') : null;", $template);
        self::assertStringContainsString('<?php if ($summary !== null && !$summary->isEmpty()): ?>', $template);
        self::assertStringContainsString('<div class="card-summary rich-text"><?= $summary->html ?></div>', $template);
        self::assertStringNotContainsString('$this->e($summary)', $template, 'a RichText is not Stringable');
    }
}
