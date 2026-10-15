<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryRepository;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class EntryPickersTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // Manages events but may not view notes, the target collection of the events' "speakers" relation.
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'eventor' => ['label' => 'Eventor', 'permissions' => ['entries.events.*']],
        ]));
    }

    public function testRelationsToCollectionsTheUserCannotViewAreKept(): void
    {
        $note = $this->entry('notes', 'Secret speaker', ['fields' => ['text' => 'x']]);
        $event = $this->entry('events', 'Concert', ['slug' => 'concert', 'fields' => ['speakers' => [$note->id]]]);
        $this->actingAs($this->createUser('ev@example.com', 'eventor'));

        $form = (string) $this->browser->get('/admin/content/events/' . $event->id)->getBody();
        self::assertStringNotContainsString('Secret speaker', $form);
        self::assertStringContainsString('<input type="hidden" name="fields[speakers][]" value="' . $note->id . '">', $form);

        $saved = $this->browser->post('/admin/content/events/' . $event->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'title' => 'Concert!',
            'status' => 'published',
            'fields' => ['venue' => 'Hall', 'speakers' => [(string) $note->id]],
        ]);

        self::assertSame(303, $saved->getStatusCode());
        self::assertSame([$note->id], $this->app->container()->service(EntryRepository::class)->find($event->id)?->field('speakers'));
    }

    public function testForgedRelationIdsAreRejectedAndNotCarriedOver(): void
    {
        $note = $this->entry('notes', 'Visible speaker', ['fields' => ['text' => 'x']]);
        $event = $this->entry('events', 'Concert', ['slug' => 'concert']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringContainsString(
            '<option value="' . $note->id . '">Visible speaker</option>',
            (string) $this->browser->get('/admin/content/events/' . $event->id)->getBody(),
        );

        $forged = $this->browser->post('/admin/content/events/' . $event->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'title' => 'Concert',
            'status' => 'published',
            'fields' => ['speakers' => ['999999']],
        ]);

        self::assertSame(422, $forged->getStatusCode());
        self::assertStringContainsString('Choose existing entries.', (string) $forged->getBody());
        self::assertStringNotContainsString('value="999999"', (string) $forged->getBody());
    }
}
