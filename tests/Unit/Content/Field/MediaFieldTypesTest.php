<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldError;
use AtarCms\Content\Field\MediaFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaFieldTypesTest extends TestCase
{
    public function testImageAndFileStoreOneId(): void
    {
        foreach ([Field::image('cover'), Field::file('attachment')] as $field) {
            $type = $field->type;
            self::assertInstanceOf(MediaFieldType::class, $type);
            self::assertSame(12, $type->normalize('12', $field));
            self::assertSame(12, $type->normalize(' 12 ', $field));
            self::assertSame(12, $type->normalize(12, $field));
            self::assertNull($type->normalize('', $field));
            self::assertNull($type->normalize(null, $field));
            self::assertSame(12, $type->read(12, $field));
            self::assertNull($type->read('12', $field));
            self::assertNull($type->read(null, $field));
            self::assertSame([12], $type->mediaIds(12));
            self::assertSame([], $type->mediaIds('12'));
            self::assertNull($type->columnType($field));
        }
        $image = Field::image('cover')->type;
        $file = Field::file('attachment')->type;
        self::assertInstanceOf(MediaFieldType::class, $image);
        self::assertInstanceOf(MediaFieldType::class, $file);
        self::assertSame(['image', true], [$image->name(), $image->imagesOnly()]);
        self::assertSame(['file', false], [$file->name(), $file->imagesOnly()]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIds(): iterable
    {
        yield 'word' => ['abc'];
        yield 'zero string' => ['0'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'list in a single field' => ['12,13'];
        yield 'float' => [1.5];
        yield 'array' => [[12]];
        yield 'too large string' => ['4294967296'];
        yield 'too large' => [4294967296];
        yield 'bool' => [true];
    }

    #[DataProvider('malformedIds')]
    public function testRejectsMalformedIds(mixed $input): void
    {
        $field = Field::image('cover');

        try {
            $field->type->normalize($input, $field);
            self::fail('Expected FieldError');
        } catch (FieldError $e) {
            self::assertSame('validation.field.media', $e->key);
        }
    }

    public function testGalleryStoresAnOrderedListOfUniqueIds(): void
    {
        $field = Field::gallery('images')->max(2);
        $type = $field->type;
        self::assertInstanceOf(MediaFieldType::class, $type);

        self::assertSame('gallery', $type->name());
        self::assertTrue($type->imagesOnly());
        self::assertSame([3, 1], $type->normalize('3, 1,3', $field));
        self::assertSame([3, 1], $type->normalize([3, '1', ''], $field));
        self::assertSame([7], $type->normalize(7, $field));
        self::assertNull($type->normalize('', $field));
        self::assertNull($type->normalize(' , ', $field));
        self::assertSame([3, 1], $type->read([3, 'x', 0, 1], $field));
        self::assertSame([], $type->read('3', $field));
        self::assertSame([3, 1], $type->mediaIds([3, 1]));

        try {
            $type->normalize('1,2,3', $field);
            self::fail('Expected FieldError');
        } catch (FieldError $e) {
            self::assertSame(['validation.field.too_many', ['max' => 2]], [$e->key, $e->params]);
        }
        try {
            $type->normalize('1,x', $field);
            self::fail('Expected FieldError');
        } catch (FieldError $e) {
            self::assertSame('validation.field.media', $e->key);
        }
    }
}
