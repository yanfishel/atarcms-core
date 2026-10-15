<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Cli\Command\MediaReferencesPruneCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Tests\Support\Cast;
use RuntimeException;

final class MediaReferencesPruneCommandTest extends ContentTestCase
{
    public function testReleasesMediaOfRemovedCollectionsAndFieldsOnly(): void
    {
        $cover = $this->insertMedia();
        $linked = $this->insertMedia();
        $article = $this->insertEntry(['slug' => 'kept']);
        $retired = $this->insertEntry(['collection' => 'retired', 'slug' => 'gone']);
        foreach ([[$article, 'cover', $cover], [$article, 'body', $linked], [$article, 'old_field', $linked], [$retired, 'cover', $cover]] as [$entry, $field, $media]) {
            $this->db->insert('entry_media', ['entry_id' => $entry, 'field' => $field, 'media_id' => $media]);
        }

        self::assertSame("Removed 2 media references of removed collections or fields.\n", $this->prune());

        self::assertSame(
            [[$article, 'body', $linked], [$article, 'cover', $cover]],
            array_map(
                static fn(array $row): array => [Cast::int($row['entry_id']), Cast::string($row['field']), Cast::int($row['media_id'])],
                $this->db->select('SELECT entry_id, field, media_id FROM entry_media ORDER BY entry_id, field'),
            ),
        );
        self::assertSame("Removed 0 media references of removed collections or fields.\n", $this->prune());
    }

    public function testPrunesAllRowsOfAConfiguredCollectionWithoutMediaFields(): void
    {
        $media = $this->insertMedia();
        $article = $this->insertEntry(['slug' => 'kept']);
        $note = $this->insertEntry(['collection' => 'notes', 'slug' => 'note']);
        $this->db->insert('entry_media', ['entry_id' => $article, 'field' => 'cover', 'media_id' => $media]);
        $this->db->insert('entry_media', ['entry_id' => $note, 'field' => 'cover', 'media_id' => $media]);

        self::assertSame('Removed 1 media reference of removed collections or fields.
', $this->prune());

        self::assertSame(
            [$article],
            array_map(static fn(array $row): int => Cast::int($row['entry_id']), $this->db->select('SELECT entry_id FROM entry_media')),
        );
    }

    private function prune(): string
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $command = new MediaReferencesPruneCommand($this->collections, $this->mediaRepository());

        self::assertSame(0, $command->run(Input::parse([]), new Output($stdout, $stderr)));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
