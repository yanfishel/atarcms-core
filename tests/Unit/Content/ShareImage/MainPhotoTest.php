<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\ShareImage;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Seo;
use AtarCms\Content\ShareImage\MainPhoto;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MainPhotoTest extends TestCase
{
    public function testTheCandidatesAreTheFirstIdOfEachShareFieldInOrder(): void
    {
        $collection = Collection::make('news')->fields([Field::image('cover'), Field::gallery('images'), Field::file('doc')]);

        self::assertSame([7, 3], MainPhoto::candidates(self::entry($collection, ['cover' => 7, 'images' => [3, 4], 'doc' => 9])));
        self::assertSame([3], MainPhoto::candidates(self::entry($collection, ['images' => [3, 4]])), 'the gallery\'s first photo');
        self::assertSame([], MainPhoto::candidates(self::entry($collection, ['doc' => 9])));
    }

    public function testTheOverrideDecidesTheOrder(): void
    {
        $collection = Collection::make('news')->fields([Field::image('cover'), Field::gallery('images')])->shareImage('images', 'cover');
        self::assertSame([3, 7], MainPhoto::candidates(self::entry($collection, ['cover' => 7, 'images' => [3]])));

        $off = Collection::make('news')->fields([Field::image('cover')])->shareImage();
        self::assertSame([], MainPhoto::candidates(self::entry($off, ['cover' => 7])));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function entry(Collection $collection, array $data): Entry
    {
        $now = new DateTimeImmutable('2026-10-04 10:00:00');

        return new Entry(1, $collection, 'en', 'group', 'slug', 'Title', EntryStatus::Published, $now, null, 0, null, $now, $now, new Seo(), $data);
    }
}
