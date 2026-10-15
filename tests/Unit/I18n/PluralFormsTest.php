<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\PluralForms;
use MessageFormatter;
use PHPUnit\Framework\TestCase;

final class PluralFormsTest extends TestCase
{
    /**
     * A script cannot translate, so the server gives it the message of every plural category a whole number takes in
     * the locale, with "#" where the number goes; Intl.PluralRules picks the category in the browser.
     */
    public function testEveryCategoryOfAWholeNumberGetsItsMessageWithAMarkForTheNumber(): void
    {
        $en = '{count, plural, one {# subsection} other {# subsections}}';
        $ru = '{count, plural, one {# подраздел} few {# подраздела} many {# подразделов} other {# подраздела}}';

        self::assertSame(
            ['other' => '# subsections', 'one' => '# subsection'],
            PluralForms::of('en', static fn(int $count): string => (string) MessageFormatter::formatMessage('en', $en, ['count' => $count])),
        );
        self::assertSame(
            ['many' => '# подразделов', 'one' => '# подраздел', 'few' => '# подраздела'],
            PluralForms::of('ru', static fn(int $count): string => (string) MessageFormatter::formatMessage('ru', $ru, ['count' => $count])),
            'Russian "other" is only for fractions',
        );
    }

    public function testOnlyTheNumberIsMarked(): void
    {
        self::assertSame(
            ['other' => 'Level 10: # items', 'one' => 'Level 10: # item'],
            PluralForms::of('en', static fn(int $count): string => 'Level 10: ' . $count . ($count === 1 ? ' item' : ' items')),
        );
    }
}
