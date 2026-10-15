<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldError;
use AtarCms\Content\Field\MediaFieldType;
use AtarCms\Content\Field\Slide;
use AtarCms\Content\Field\SlidesType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlidesTypeTest extends TestCase
{
    public function testIsAMediaFieldOfImages(): void
    {
        $field = Field::slides('hero');
        $type = $field->type;

        self::assertInstanceOf(SlidesType::class, $type);
        self::assertInstanceOf(MediaFieldType::class, $type);
        self::assertSame('slides', $type->name());
        self::assertTrue($type->imagesOnly());
        self::assertNull($type->columnType($field));
        self::assertSame('content', $field->tab);
    }

    public function testNormalizesThePostedRowsIntoAList(): void
    {
        $field = Field::slides('hero');

        $stored = $field->type->normalize([
            '3' => ['image' => ' 12 ', 'title' => '  Spring  ', 'subtitle' => '', 'url' => '/about', 'button' => 'More'],
            '7' => ['image' => '5', 'title' => '', 'subtitle' => "Two\nlines", 'url' => '', 'button' => ''],
            // A new row left empty is no slide.
            '9' => ['image' => '', 'title' => ' ', 'subtitle' => '', 'url' => '', 'button' => ''],
        ], $field);

        self::assertSame([
            ['image' => 12, 'title' => 'Spring', 'url' => '/about', 'button' => 'More'],
            ['image' => 5, 'subtitle' => 'Two lines'],
        ], $stored);
        self::assertSame($stored, $field->type->normalize($stored, $field), 'a stored value normalizes to itself');
    }

    public function testASlideMayCarryAVideo(): void
    {
        $field = Field::slides('hero');

        $stored = $field->type->normalize([
            ['image' => '12', 'video' => ' 30 ', 'title' => 'Waves'],
            ['image' => '5', 'video' => ''],
            ['image' => '6', 'video' => null],
        ], $field);

        self::assertSame([['image' => 12, 'video' => 30, 'title' => 'Waves'], ['image' => 5], ['image' => 6]], $stored);
        self::assertSame($stored, $field->type->normalize($stored, $field), 'a stored value normalizes to itself');
    }

    public function testPositionsSortAndDeleteDrops(): void
    {
        $field = Field::slides('hero');

        $stored = $field->type->normalize([
            ['image' => '1', 'position' => '3'],
            ['image' => '2', 'position' => '1'],
            ['image' => '3', 'position' => '2', 'delete' => '1'],
            ['image' => '4', 'position' => ''],
            ['image' => '5', 'position' => '2'],
            ['image' => '6', 'delete' => '0'],
        ], $field);

        self::assertSame([[ 'image' => 2], ['image' => 5], ['image' => 1], ['image' => 4], ['image' => 6]], $stored, 'unnumbered rows follow in posted order');
    }

    public function testEmptyIsNull(): void
    {
        $field = Field::slides('hero');

        self::assertNull($field->type->normalize(null, $field));
        self::assertNull($field->type->normalize('', $field));
        self::assertNull($field->type->normalize([], $field));
        self::assertNull($field->type->normalize([['image' => '', 'title' => '']], $field));
        self::assertNull($field->type->normalize([['image' => '4', 'delete' => 'on']], $field));
    }

    public function testKeepsAllowedLinksOnly(): void
    {
        $field = Field::slides('hero');
        foreach (['/news', 'https://example.org/x?y=1', '#contact'] as $url) {
            self::assertSame([['image' => 1, 'url' => $url]], $field->type->normalize([['image' => '1', 'url' => $url]], $field));
        }
        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', '//evil.example', 'ftp://example.org'] as $url) {
            self::assertError(['validation.slides.url', ['slide' => 1]], [['image' => '1', 'url' => $url]]);
        }
    }

    /**
     * @return iterable<string, array{array{string, array<string, int|string>}, mixed}>
     */
    public static function refusals(): iterable
    {
        yield 'a string' => [['validation.field.invalid', []], 'abc'];
        yield 'a number' => [['validation.field.invalid', []], 12];
        yield 'a row that is a string' => [['validation.field.invalid', []], ['x']];
        yield 'a text that is an array' => [['validation.field.invalid', []], [['image' => '1', 'title' => ['x']]]];
        yield 'an image that is an array' => [['validation.field.media', []], [['image' => ['1']]]];
        yield 'a bad image id' => [['validation.field.media', []], [['image' => 'abc']]];
        yield 'a video that is an array' => [['validation.field.media', []], [['image' => '1', 'video' => ['2']]]];
        yield 'a bad video id' => [['validation.field.media', []], [['image' => '1', 'video' => '-3']]];
        yield 'a video without an image' => [['validation.slides.image', ['slide' => 1]], [['video' => '2']]];
        yield 'a text without an image' => [['validation.slides.image', ['slide' => 2]], [['image' => '1'], ['title' => 'No photo']]];
        yield 'a long title' => [['validation.slides.title_long', ['slide' => 1, 'max' => 255]], [['image' => '1', 'title' => str_repeat('a', 256)]]];
        yield 'a long subtitle' => [['validation.slides.subtitle_long', ['slide' => 1, 'max' => 500]], [['image' => '1', 'subtitle' => str_repeat('a', 501)]]];
        yield 'a long link' => [['validation.slides.url_long', ['slide' => 1, 'max' => 255]], [['image' => '1', 'url' => '/' . str_repeat('a', 255)]]];
        yield 'a long button' => [['validation.slides.button_long', ['slide' => 1, 'max' => 100]], [['image' => '1', 'button' => str_repeat('a', 101)]]];
    }

    /**
     * @param array{string, array<string, int|string>} $error
     */
    #[DataProvider('refusals')]
    public function testRefusesCraftedInput(array $error, mixed $input): void
    {
        self::assertError($error, $input);
    }

    public function testLimitsTheNumberOfSlides(): void
    {
        $eleven = array_map(static fn(int $id): array => ['image' => (string) $id], range(1, 11));
        self::assertError(['validation.field.too_many', ['max' => 10]], $eleven);

        $field = Field::slides('hero')->max(2);
        try {
            $field->type->normalize(array_slice($eleven, 0, 3), $field);
            self::fail('Expected FieldError');
        } catch (FieldError $e) {
            self::assertSame(['validation.field.too_many', ['max' => 2]], [$e->key, $e->params]);
        }
    }

    public function testReadsSlidesAndSkipsMalformedItems(): void
    {
        $field = Field::slides('hero');

        $slides = $field->type->read([
            ['image' => 12, 'title' => 'Spring', 'url' => '/about', 'button' => 'More'],
            'junk',
            ['title' => 'No image'],
            ['image' => '5'],
            ['image' => 7, 'subtitle' => 'Sub', 'title' => 3],
            ['image' => 8, 'video' => 30],
            ['image' => 9, 'video' => '31'],
        ], $field);

        self::assertEquals([
            new Slide(12, 'Spring', null, '/about', 'More'),
            new Slide(7, null, 'Sub', null, null),
            new Slide(8, videoId: 30),
            new Slide(9),
        ], $slides);
        self::assertIsArray($slides);
        self::assertInstanceOf(Slide::class, $slides[0]);
        self::assertNull($slides[0]->videoId);
        self::assertSame([], $field->type->read(null, $field));
        self::assertSame([], $field->type->read('x', $field));
    }

    public function testMediaIdsAreTheUniqueImagesInOrder(): void
    {
        $type = Field::slides('hero')->type;
        self::assertInstanceOf(MediaFieldType::class, $type);

        self::assertSame([12, 5], $type->mediaIds([['image' => 12], ['image' => 5], ['image' => 12], ['title' => 'x'], 'junk', ['image' => '3']]));
        self::assertSame([12, 30, 5], $type->mediaIds([['image' => 12, 'video' => 30], ['image' => 5, 'video' => 30]]), 'pictures and videos, each once');
        self::assertSame([12, 30, 5, 6], $type->mediaIds([['image' => 12, 'video' => 30], ['image' => 5, 'video' => 30], ['image' => 6, 'video' => '7']]), 'a malformed video id is no reference');
        self::assertSame([], $type->mediaIds(null));
        self::assertSame([], $type->mediaIds(12));
    }

    public function testRowsReadAnyValueLeniently(): void
    {
        self::assertSame([
            ['image' => '2', 'video' => '', 'title' => 'B', 'subtitle' => '', 'url' => '', 'button' => ''],
            ['image' => '12', 'video' => '30', 'title' => 'A', 'subtitle' => '', 'url' => '/x', 'button' => ''],
        ], SlidesType::rows([
            ['image' => 12, 'video' => 30, 'title' => 'A', 'url' => '/x', 'position' => '2'],
            ['image' => '2', 'title' => 'B', 'position' => '1'],
            ['image' => '3', 'delete' => '1'],
            ['image' => '', 'title' => ''],
            'junk',
            ['image' => ['x'], 'title' => ['y']],
        ]));
        self::assertSame([], SlidesType::rows('x'));
        self::assertSame([], SlidesType::rows(null));
    }

    /**
     * @param array{string, array<string, int|string>} $error
     */
    private static function assertError(array $error, mixed $input): void
    {
        $field = Field::slides('hero');
        try {
            $field->type->normalize($input, $field);
            self::fail('Expected FieldError');
        } catch (FieldError $e) {
            self::assertSame($error, [$e->key, $e->params]);
        }
    }
}
