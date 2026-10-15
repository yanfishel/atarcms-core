<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\TreeOrder;
use AtarCms\Tests\Support\Cast;
use InvalidArgumentException;

final class TermReorderTest extends ContentTestCase
{
    public function testMovesTermsAndTouchesOnlyTheMovedOnes(): void
    {
        $code = $this->insertTerm(['slug' => 'code', 'name' => 'Code']);
        $php = $this->insertTerm(['slug' => 'php', 'name' => 'PHP', 'parent_id' => $code]);
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go', 'position' => 1]);
        $this->insertTerm(['slug' => 'kod', 'name' => 'Код', 'locale' => 'ru']);

        $this->clock->advance('+1 minute');
        $moved = $this->termService()->reorder(
            $this->collections->taxonomies->get('topics'),
            'en',
            TreeOrder::parse(sprintf('[{"id":%d,"children":[{"id":%d},{"id":%d}]}]', $code, $php, $go)),
            $this->version(),
            $this->user(),
            '10.0.0.1',
        );

        self::assertSame(1, $moved);
        $stored = $this->terms->find($go);
        self::assertSame([$code, 1, '12:01'], [$stored?->parentId, $stored?->position, $stored?->updatedAt->format('H:i')]);
        self::assertSame('12:00', $this->terms->find($php)?->updatedAt->format('H:i'));
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'term.reordered'")), true);
        self::assertSame(['taxonomy' => 'topics', 'locale' => 'en', 'moved' => 1], $meta);
    }

    public function testRejectsTamperedAndStaleTreesWithoutChangingAnything(): void
    {
        $service = $this->termService();
        $topics = $this->collections->taxonomies->get('topics');
        $code = $this->insertTerm(['slug' => 'code', 'name' => 'Code']);
        $php = $this->insertTerm(['slug' => 'php', 'name' => 'PHP', 'parent_id' => $code]);
        $tag = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'news', 'name' => 'News']);
        $russian = $this->insertTerm(['slug' => 'kod', 'name' => 'Код', 'locale' => 'ru']);
        $version = $this->version();
        $before = $this->db->select('SELECT id, parent_id, position, updated_at FROM terms ORDER BY id');

        foreach ([
            sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $code, $php, $tag),
            sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $code, $php, $russian),
            sprintf('[{"id":%d}]', $code),
            sprintf('[{"id":%d,"children":[{"id":%d}]},{"id":%d}]', $code, $php, $php),
        ] as $json) {
            self::assertValidation(['tree' => 'validation.tree.invalid'], fn() => $service->reorder($topics, 'en', TreeOrder::parse($json), $version, null, null));
        }
        $swapped = TreeOrder::parse(sprintf('[{"id":%d},{"id":%d}]', $php, $code));
        self::assertValidation(['tree' => 'validation.tree.stale'], fn() => $service->reorder($topics, 'en', $swapped, sha1('old'), null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->reorder($topics, 'de', [], $version, null, null));

        self::assertSame($before, $this->db->select('SELECT id, parent_id, position, updated_at FROM terms ORDER BY id'));
        self::assertNotContains('term.reordered', $this->auditActions());
    }

    public function testFlatTaxonomiesHaveNoOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->termService()->reorder($this->collections->taxonomies->get('tags'), 'en', [], '', null, null);
    }

    private function version(): string
    {
        return TreeOrder::version($this->terms->forTaxonomy('topics', 'en'));
    }
}
