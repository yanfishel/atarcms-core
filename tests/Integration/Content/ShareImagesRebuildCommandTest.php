<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Cli\Command\ShareImagesRebuildCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\EntryInput;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Media\Media;
use AtarCms\Media\Share\ShareLayout;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use AtarCms\View\ThemeConfig;
use RuntimeException;

final class ShareImagesRebuildCommandTest extends ContentTestCase
{
    use TempDirectory;

    public function testMakesOnlyWhatChangedAndRemovesWhatNoLongerApplies(): void
    {
        $photo = $this->photo();
        $service = $this->service(shareImages: $this->shareImageService());
        $shared = $service->create($this->collections->get('articles'), 'en', new EntryInput('One', fields: ['cover' => $photo->id], metaTitle: 'One'), null, null);
        $untitled = $service->create($this->collections->get('articles'), 'en', new EntryInput('Two', slug: 'two', fields: ['cover' => $photo->id]), null, null);
        // Saved before the title stood in for an empty title for search engines: no card yet.
        $this->entries->setShareImage($untitled->id, null, $untitled->shareImage);
        $stale = $this->insertEntry(['slug' => 'stale']);
        $this->entries->setShareImage($stale, $stale . '-0123456789abcdef.jpg', null);

        [$code, $out] = $this->rebuild([], $this->shareImageService());
        self::assertSame(0, $code);
        self::assertSame("Made 1, unchanged 1, removed 1, failed 0.\n", $out, 'the shared entry is current; the untitled one gets its card from its title; the stale name of an entry without a photo goes');
        self::assertNotNull($this->entries->find($untitled->id)?->shareImage);

        [, $out] = $this->rebuild([], $this->shareImageService(new ThemeConfig(shareLayout: ShareLayout::Panel)));
        self::assertSame("Made 2, unchanged 0, removed 0, failed 0.\n", $out, 'a new layout draws the images again');
        self::assertNotSame($shared->shareImage, $this->entries->find($shared->id)?->shareImage);

        [, $out] = $this->rebuild(['--collection=pages'], $this->shareImageService());
        self::assertSame("Made 0, unchanged 0, removed 0, failed 0.\n", $out, 'only that collection');
    }

    public function testAnUnknownCollectionIsAnError(): void
    {
        [$code, , $err] = $this->rebuild(['--collection=ghost'], $this->shareImageService());

        self::assertSame(1, $code);
        self::assertSame("Unknown collection \"ghost\".\n", $err);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function rebuild(array $arguments, ShareImages $shareImages): array
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = new ShareImagesRebuildCommand($this->collections, $this->entries, $shareImages)->run(Input::parse($arguments), new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function photo(): Media
    {
        return $this->mediaService()->upload(TestImages::uploaded(TestImages::jpeg(1600, 1000), 'photo.jpg', 'image/jpeg'), null, null);
    }
}
