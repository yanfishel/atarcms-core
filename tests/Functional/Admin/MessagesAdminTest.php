<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Functional\FunctionalTestCase;

final class MessagesAdminTest extends FunctionalTestCase
{
    public function testTheListShowsNewMessagesFirstAndUnreadInBold(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->message('Anna <b>', '-2 hours', read: true);
        $this->message('Boris', '-1 hour', body: "Hello!\nPlease call me back about the order.");

        $page = (string) $this->browser->get('/admin/messages')->getBody();

        $sidebar = substr($page, (int) strpos($page, '<aside class="admin-sidebar"'), (int) strpos($page, '</aside>') - (int) strpos($page, '<aside class="admin-sidebar"'));
        self::assertStringNotContainsString('href="/admin/messages"', $sidebar, 'the inbox is in the top bar');
        self::assertMatchesRegularExpression('~<div class="admin-page-header">\s*<h1>Messages</h1>~', $page);
        self::assertLessThan((int) strpos($page, 'Anna &lt;b&gt;'), (int) strpos($page, '>Boris</a>'), 'newest first');
        self::assertMatchesRegularExpression('~<tr class="admin-table-row admin-message-unread">\s*<td data-label="From">\s*<a class="admin-row-link" href="/admin/messages/\d+">Boris</a>\s*<span class="admin-chip admin-chip-unread">New</span>~', $page);
        self::assertStringContainsString('<span class="admin-message-preview">Hello! Please call me back about the order.</span>', $page);
        self::assertMatchesRegularExpression('~<tr class="admin-table-row">\s*<td data-label="From">\s*<a class="admin-row-link" href="/admin/messages/\d+">Anna &lt;b&gt;</a>\s*<span class="admin-message-email" dir="ltr">~', $page);
        self::assertStringContainsString('<td data-label="Language">English</td>', $page);
    }

    public function testTheTopBarInboxLeadsToTheMessagesWithTheUnreadCount(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $inbox = '~<div class="admin-topbar-menus">\s*<a class="admin-menu-trigger admin-inbox" href="/admin/messages" title="%1$s" aria-label="%1$s"><svg[^>]*><use href="[^"]+#inbox"></use></svg>%2$s</a>\s*<details class="admin-menu admin-theme-menu" data-menu>~';
        self::assertMatchesRegularExpression(sprintf($inbox, 'Messages', ''), (string) $this->browser->get('/admin')->getBody(), 'no badge without unread messages, first in the top bar, before the theme');

        $this->message('Anna', '-2 hours');
        $this->message('Boris', '-1 hour');
        $this->message('Clara', '-1 hour', read: true);
        self::assertMatchesRegularExpression(sprintf($inbox, 'Messages: 2 new', '<span class="admin-inbox-count" aria-hidden="true">2</span>'), (string) $this->browser->get('/admin')->getBody());

        $this->actingAs($this->createUser('author@example.com', 'author'));
        self::assertStringNotContainsString('admin-inbox', (string) $this->browser->get('/admin')->getBody(), 'only for those who read the messages');
    }

    public function testAnEmptyListInvitesNothingButExplains(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/messages')->getBody();

        self::assertStringContainsString('<p class="admin-empty-title">No messages yet.</p>', $page);
    }

    public function testOpeningAMessageMarksItReadAndItCanBeMarkedUnread(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $id = $this->message('Anna', '-1 hour', body: "Line one\nLine two");
        $messages = $this->app->container()->service(MessageRepository::class);

        $page = (string) $this->browser->get('/admin/messages/' . $id)->getBody();

        self::assertNotNull($messages->find($id)?->readAt);
        self::assertStringContainsString('<h1>Message from Anna</h1>', $page);
        self::assertStringContainsString("<div class=\"admin-message-body\">Line one\nLine two</div>", $page);
        self::assertStringContainsString('<a href="mailto:anna%40example.com" dir="ltr">anna@example.com</a>', $page);
        self::assertStringContainsString('Not emailed (mail is not set up, or sending failed): read it here.', $page);
        self::assertStringContainsString('href="/admin/messages/' . $id . '/delete"', $page);

        $response = $this->browser->post('/admin/messages/' . $id . '/unread', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/messages', $response->getHeaderLine('Location'));
        self::assertNull($messages->find($id)->readAt);
        self::assertStringContainsString('Marked as unread.', (string) $this->browser->get('/admin/messages')->getBody());
    }

    public function testDeletingAMessageIsConfirmedAndAudited(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $id = $this->message('Anna', '-1 hour');

        $confirm = (string) $this->browser->get('/admin/messages/' . $id . '/delete')->getBody();
        self::assertStringContainsString('Delete the message from “Anna”? This cannot be undone.', $confirm);
        self::assertStringContainsString('<form class="admin-confirm-actions" method="post" action="/admin/messages/' . $id . '/delete">', $confirm);

        $response = $this->browser->post('/admin/messages/' . $id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->app->container()->service(MessageRepository::class)->find($id));
        self::assertContains('message.deleted', $this->auditActions());
        self::assertStringContainsString('Message deleted.', (string) $this->browser->get('/admin/messages')->getBody());
        self::assertStringContainsString('Message: “Anna”', (string) $this->browser->get('/admin/audit')->getBody());
        self::assertSame(404, $this->browser->get('/admin/messages/' . $id)->getStatusCode());
    }

    public function testOnlyThoseWhoManageMessagesSeeThem(): void
    {
        $id = $this->message('Anna', '-1 hour');
        $this->actingAs($this->createUser('author@example.com', 'author'));

        self::assertSame(403, $this->browser->get('/admin/messages')->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/messages/' . $id)->getStatusCode());
        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringNotContainsString('/admin/messages', $dashboard);
    }

    public function testTheDashboardLeavesNewMessagesToTheTopBar(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->message('Anna', '-2 hours');

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringNotContainsString('admin-alert-info', $dashboard);
        self::assertStringNotContainsString('new message', $dashboard);
    }

    private function message(string $name, string $age, string $body = 'Hello, I would like to know more.', bool $read = false): int
    {
        $created = new \DateTimeImmutable($age);

        return $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'contact',
            'locale' => 'en',
            'name' => $name,
            'email' => strtolower(explode(' ', $name)[0]) . '@example.com',
            'phone' => null,
            'body' => $body,
            'page_url' => 'https://localhost/contacts',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => $created,
            'read_at' => $read ? $created : null,
        ]);
    }
}
