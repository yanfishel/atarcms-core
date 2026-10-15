<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Settings\ContactPage;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Support\Cast;

/**
 * The contact page (Plan 26): its entry form's side column holds the contact details (Address with the map, Contacts,
 * Social), saved with the page; the map is added, changed and removed in a dialog (htmx) or on full pages (without
 * JavaScript); the theme shows it on the contacts page in every language.
 */
final class ContactPageAdminTest extends SkeletonTestCase
{
    private const string PB = '!1m18!1m12!1m3!1d3381.0!2d34.7818!3d32.0853!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!5e0!3m2!1sen!2sil!4v1700000000000!5m2!1sen!2sil';

    private ?Entry $contacts = null;

    public function testTheContactsFormHasTheContactDetailsInThreeSideTabs(): void
    {
        $this->app->container()->service(SettingsService::class)->saveContacts('en', new SettingsInput(phone: '+1 555 0100', address: 'Main St 1', social: ['vk' => 'https://vk.com/club']), null, null);
        $this->actingAs($this->createUser('admin@example.com'));
        $form = (string) $this->browser->get($this->contactsForm())->getBody();

        self::assertStringContainsString('<div class="admin-form-layout">', $form, 'a plain single gets a side column');
        self::assertStringContainsString('<div class="admin-form-column" data-tabs="admin.tabs.contacts.side">', $form);
        foreach (['address' => 'Address', 'contacts' => 'Contacts', 'social' => 'Social'] as $tab => $label) {
            self::assertMatchesRegularExpression('~<button type="button" class="admin-tab" role="tab" id="tab-' . $tab . '" aria-controls="panel-' . $tab . '" aria-selected="(true|false)"[^>]*data-errors="0">\s*' . $label . '\s*</button>~', $form, $tab);
        }
        $address = substr($form, (int) strpos($form, 'id="panel-address"'), (int) strpos($form, 'id="panel-contacts"') - (int) strpos($form, 'id="panel-address"'));
        self::assertMatchesRegularExpression('~<textarea id="address" name="address" maxlength="500" rows="3"[^>]*>Main St 1</textarea>~', $address);
        self::assertStringContainsString('name="opening_hours"', $address);
        self::assertMatchesRegularExpression('~<section class="admin-map-panel" id="contact-map" aria-labelledby="contact-map-title">~', $address, 'the map is in the Address tab');
        self::assertStringContainsString('<input type="tel" id="phone" name="phone" maxlength="100" value="+1 555 0100" dir="ltr">', $form, 'site-wide, written left to right');
        self::assertStringContainsString('name="telegram"', $form);
        self::assertStringContainsString('value="https://vk.com/club"', $form);
        preg_match_all('~name="social_([a-z_]+)"~', $form, $social);
        self::assertSame(Settings::SOCIAL_NETWORKS, $social[1], 'the networks in the list order, the Russian ones last');
        self::assertStringNotContainsString('One map for the whole site', $form);
        self::assertStringContainsString('No map yet', $form);
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-secondary" href="/admin/contact-map" data-dialog-open><svg[^>]*><use href="[^"]+#plus"></use></svg>Add map</a>~', $form);
        self::assertStringNotContainsString('/admin/contact-map/delete', $form, 'nothing to remove yet');
        self::assertMatchesRegularExpression('~</form>\s*<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">\s*<div class="admin-dialog-content" data-dialog-content></div>\s*<template data-dialog-toast>~', $form, 'the dialog sits outside the entry form');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/dialog\.js\?v=~', $form);

        $news = (string) $this->browser->get('/admin/content/news/new')->getBody();
        self::assertStringNotContainsString('contact-map', $news, 'only the contact page has it');
    }

    public function testTheDialogHasFourWaysEachItsOwnForm(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $fragment = (string) $this->browser->get('/admin/contact-map', ['HX-Request' => 'true'])->getBody();

        self::assertMatchesRegularExpression('~^\s*<div class="admin-dialog-header">\s*<h2 id="dialog-title">Map</h2>~', $fragment);
        foreach (['embed' => 'Embed code', 'link' => 'Google Maps link', 'address' => 'Address', 'coordinates' => 'Coordinates'] as $method => $label) {
            self::assertMatchesRegularExpression('~<button type="button" class="admin-tab" role="tab" id="tab-map-' . $method . '" aria-controls="panel-map-' . $method . '"[^>]*>\s*' . $label . '~', $fragment, $method);
            self::assertMatchesRegularExpression('~<form class="admin-map-form" id="map-form-' . $method . '" method="post" action="/admin/contact-map" hx-post="/admin/contact-map" hx-target="closest \[data-dialog-content\]" hx-swap="innerHTML">\s*<input type="hidden" name="_csrf" value="[^"]+">\s*<input type="hidden" name="method" value="' . $method . '">~', $fragment, $method);
            self::assertMatchesRegularExpression('~<button type="submit" class="admin-button admin-button-primary" form="map-form-' . $method . '" data-tab-for="map-' . $method . '"~', $fragment, $method);
        }
        self::assertMatchesRegularExpression('~<div class="admin-dialog-footer">\s*<button type="button" class="admin-button admin-button-secondary" data-dialog-close>Cancel</button>~', $fragment);
        self::assertStringContainsString('<textarea id="map-embed" name="embed" rows="5" dir="ltr" autofocus></textarea>', $fragment);
        self::assertStringContainsString('<input id="map-latitude" name="latitude" inputmode="decimal" maxlength="40" value="" dir="ltr">', $fragment);
    }

    public function testASavedMapSwapsTheColumnInPlaceAndShowsAToast(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $response = $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'coordinates', 'latitude' => '32.0853, 34.7818', 'zoom' => '15'], ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('#contact-map', $response->getHeaderLine('HX-Retarget'));
        self::assertSame('outerHTML', $response->getHeaderLine('HX-Reswap'));
        self::assertSame(['atar-dialog-done' => ['toast' => 'The map is saved.', 'focus' => 'contact-map']], json_decode($response->getHeaderLine('HX-Trigger-After-Settle'), true));
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $response->getHeaderLine('HX-Trigger-After-Settle'), 'a header is ASCII');
        $panel = (string) $response->getBody();
        self::assertMatchesRegularExpression('~^\s*<section class="admin-map-panel" id="contact-map"~', $panel);
        self::assertMatchesRegularExpression('~<a class="admin-map-waze" href="https://waze.com/ul\?ll=32.085300%2C34.781800&amp;navigate=yes" target="_blank" rel="noopener"><svg[^>]*><use href="[^"]+#external-link"></use></svg>Directions in Waze</a>\s*<iframe class="admin-map-frame"~', $panel, 'the route the site shows, above the map');
        self::assertStringContainsString('<iframe class="admin-map-frame" src="https://www.google.com/maps?q=32.085300%2C34.781800&amp;z=15&amp;hl=en&amp;output=embed" title="The map on the site" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe>', $panel);
        self::assertMatchesRegularExpression('~href="/admin/contact-map" data-dialog-open><svg[^>]*><use href="[^"]+#pencil"></use></svg>Change</a>~', $panel);
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-link admin-button-danger" href="/admin/contact-map/delete" data-dialog-open><svg[^>]*><use href="[^"]+#trash-2"></use></svg>Remove map</a>~', $panel);
        self::assertEquals(GoogleMap::query('32.085300,34.781800', 15), $this->map());
        self::assertSame(['settings.updated'], $this->auditActions());

        // The dialog opens on the way the map was entered, with its values.
        $fragment = (string) $this->browser->get('/admin/contact-map', ['HX-Request' => 'true'])->getBody();
        self::assertMatchesRegularExpression('~id="tab-map-coordinates" aria-controls="panel-map-coordinates" aria-selected="true"~', $fragment);
        self::assertStringContainsString('name="latitude" inputmode="decimal" maxlength="40" value="32.085300"', $fragment);
        self::assertStringContainsString('name="zoom" min="3" max="19" value="15"', $fragment);
    }

    public function testARefusedInputComesBackIntoTheDialogAtItsField(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $response = $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'link', 'link' => 'https://maps.app.goo.gl/AbC'], ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode(), 'htmx swaps no error response');
        self::assertSame('', $response->getHeaderLine('HX-Retarget'));
        $fragment = (string) $response->getBody();
        self::assertMatchesRegularExpression('~<div class="admin-alert" role="alert">.*?Please fix 1 field.~s', $fragment);
        self::assertMatchesRegularExpression('~id="tab-map-link" aria-controls="panel-map-link" aria-selected="true"[^>]* data-errors="1"~', $fragment);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $fragment);
        self::assertStringContainsString('value="https://maps.app.goo.gl/AbC" dir="ltr" aria-invalid="true" aria-describedby="map-link-error"', $fragment);
        self::assertStringContainsString('<span class="admin-field-error" id="map-link-error">A short link cannot be read: open it and copy the full address from the browser’s address bar.</span>', $fragment);
        self::assertNull($this->map());
    }

    public function testWithoutJavaScriptTheDialogIsAFullPageThatReturnsToTheForm(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $page = (string) $this->browser->get('/admin/contact-map')->getBody();
        self::assertStringContainsString('<title>Map', $page);
        foreach (['embed', 'link', 'address', 'coordinates'] as $method) {
            self::assertMatchesRegularExpression('~<form class="admin-map-form" id="map-form-' . $method . '" method="post" action="/admin/contact-map">.*?<button type="submit" class="admin-button admin-button-primary">~s', $page, $method);
        }

        $refused = $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'embed', 'embed' => '<iframe src="https://evil.com/maps/embed?pb=!1m2"></iframe>']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('id="map-embed-error"', (string) $refused->getBody());

        $saved = $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'embed', 'embed' => '<iframe src="https://www.google.com/maps/embed?pb=' . self::PB . '" width="600"></iframe>']);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/content/contacts', $saved->getHeaderLine('Location'));
        self::assertEquals(GoogleMap::embed(self::PB), $this->map());
        $form = (string) $this->browser->get($this->contactsForm())->getBody();
        self::assertStringContainsString('The map is saved.', $form);
        self::assertStringContainsString('src="https://www.google.com/maps/embed?pb=' . self::PB . '"', $form);
        self::assertStringContainsString('href="https://waze.com/ul?ll=32.0853%2C34.7818&amp;navigate=yes"', $form, 'an embed routes to its centre');

        $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'embed', 'embed' => '!1m3!1m2!1s0x0%3A0x0']);
        $form = (string) $this->browser->get($this->contactsForm())->getBody();
        self::assertStringNotContainsString('waze.com', $form);
        self::assertStringContainsString('<p class="admin-map-none">No Waze route: enter the map by its address or coordinates.</p>', $form, 'an embed without a centre has no route');

        $confirm = (string) $this->browser->get('/admin/contact-map/delete')->getBody();
        self::assertStringContainsString('Remove the map?', $confirm);
        $removed = $this->browser->post('/admin/contact-map/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $removed->getStatusCode());
        self::assertSame('/admin/content/contacts', $removed->getHeaderLine('Location'));
        self::assertNull($this->map());
        self::assertStringContainsString('The map is removed.', (string) $this->browser->get($this->contactsForm())->getBody());
    }

    public function testRemovingInTheDialogSwapsTheColumnToo(): void
    {
        $this->app->container()->service(SettingsService::class)->saveMap(GoogleMap::query('Haifa'), null, null);
        $this->actingAs($this->createUser('admin@example.com'));

        $fragment = (string) $this->browser->get('/admin/contact-map/delete', ['HX-Request' => 'true'])->getBody();
        self::assertMatchesRegularExpression('~^\s*<div class="admin-dialog-header">\s*<h2 id="dialog-title">Remove the map\?</h2>.*?<form class="admin-dialog-form" method="post" action="/admin/contact-map/delete" hx-post="/admin/contact-map/delete" hx-target="closest \[data-dialog-content\]" hx-swap="innerHTML">.*?<div class="admin-dialog-footer">.*?<button type="submit" class="admin-button admin-button-danger">~s', $fragment);

        $response = $this->browser->post('/admin/contact-map/delete', ['_csrf' => $this->csrfToken()], ['HX-Request' => 'true']);
        self::assertSame('#contact-map', $response->getHeaderLine('HX-Retarget'));
        self::assertSame(['atar-dialog-done' => ['toast' => 'The map is removed.', 'focus' => 'contact-map']], json_decode($response->getHeaderLine('HX-Trigger-After-Settle'), true));
        self::assertStringContainsString('Add map</a>', (string) $response->getBody());
        self::assertNull($this->map());
    }

    public function testThePageTextAndTheContactDetailsAreSavedTogether(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->browser->get($this->contactsForm());

        $response = $this->browser->post($this->contactsForm(), [
            ...$this->entryPost(),
            'fields' => ['body' => '<p>New text</p>'],
            'address' => "Dizengoff 50\nTel Aviv",
            'opening_hours' => 'Sun–Thu 9–18',
            'phone' => '+972 3 555 0100',
            'whatsapp' => '+972 50 555 0100',
            'telegram' => '@atar_demo',
            'social_youtube' => 'https://www.youtube.com/@atar',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $en = $this->app->container()->service(SiteSettings::class)->of('en');
        self::assertSame(["Dizengoff 50\nTel Aviv", 'Sun–Thu 9–18', '+972 3 555 0100', '+972505550100', 'atar_demo'], [$en->address, $en->openingHours, $en->phone, $en->whatsapp, $en->telegram]);
        self::assertSame('https://www.youtube.com/@atar', $en->socialLinks[0]->url ?? null);
        self::assertNull($this->app->container()->service(SiteSettings::class)->of('ru')->address, 'the address is per language');
        self::assertSame('<p>New text</p>', $this->entryBody());
        self::assertSame(['entry.updated', 'settings.updated'], array_slice($this->auditActions(), -2));
        self::assertStringContainsString('"fields":["contact.phone","contact.whatsapp","contact.telegram","contact.address","contact.opening_hours","social.youtube"]', Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'settings.updated'")));
    }

    public function testTheChatButtonSwitchFollowsTheMessengers(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $form = (string) $this->browser->get($this->contactsForm())->getBody();
        $contacts = substr($form, (int) strpos($form, 'id="panel-contacts"'), (int) strpos($form, 'id="panel-social"') - (int) strpos($form, 'id="panel-contacts"'));

        self::assertMatchesRegularExpression('~name="telegram".*<input type="checkbox" role="switch" id="chat_button" name="chat_button" value="1"[^>]*>.*Show a chat button~s', $contacts, 'after the messengers, off by default');
        self::assertStringNotContainsString('role="switch" id="chat_button" name="chat_button" value="1" checked', $contacts);

        $this->browser->post($this->contactsForm(), [...$this->entryPost(), 'whatsapp' => '+972 50 555 0100', 'chat_button' => '1']);

        self::assertTrue($this->app->container()->service(SiteSettings::class)->of('ru')->chatButton, 'site-wide');
        self::assertStringContainsString('role="switch" id="chat_button" name="chat_button" value="1" checked', (string) $this->browser->get($this->contactsForm())->getBody());
    }

    public function testARefusedContactDetailStaysAtItsFieldAndNothingIsSaved(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->browser->get($this->contactsForm());

        $response = $this->browser->post($this->contactsForm(), [
            ...$this->entryPost(),
            'fields' => ['body' => '<p>New text</p>'],
            'phone' => '+1 555 0100',
            'whatsapp' => 'call me',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p>Please fix 1 field.</p>', $body);
        self::assertMatchesRegularExpression('~id="tab-contacts" aria-controls="panel-contacts" aria-selected="true" tabindex="0" data-tab="contacts" data-errors="1"~', $body);
        self::assertStringContainsString('value="call me" aria-invalid="true" aria-describedby="whatsapp-error"', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="whatsapp-error">', $body);
        self::assertStringContainsString('New text', $body, 'the typed page text comes back');
        self::assertStringContainsString('value="+1 555 0100"', $body);
        self::assertSame('<p>Old text</p>', $this->entryBody(), 'the page is not saved');
        self::assertNull($this->app->container()->service(SiteSettings::class)->of('en')->phone, 'nor the settings');
    }

    public function testANewTranslationShowsAndSavesTheContactDetails(): void
    {
        $this->app->container()->service(SettingsService::class)->saveContacts('en', new SettingsInput(phone: '+1 555 0100', address: 'Main St 1'), null, null);
        $this->contacts();
        $this->actingAs($this->createUser('admin@example.com'));

        $form = (string) $this->browser->get('/admin/content/contacts/new?locale=ru')->getBody();
        self::assertStringContainsString('value="+1 555 0100"', $form, 'site-wide values in every language');
        self::assertStringNotContainsString('Main St 1', $form, 'the address is the language\'s');

        $response = $this->browser->post('/admin/content/contacts', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'ru',
            'version' => '',
            'fields' => ['body' => '<p>Текст</p>'],
            'address' => 'Тверская, 1',
            'phone' => '+1 555 0100',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('Тверская, 1', $this->app->container()->service(SiteSettings::class)->of('ru')->address);
        self::assertSame('Main St 1', $this->app->container()->service(SiteSettings::class)->of('en')->address);
    }

    public function testWhoCannotPublishTheContactsPageSeesNoContactDetails(): void
    {
        $this->app->container()->service(SettingsService::class)->saveMap(GoogleMap::query('Haifa'), null, null);
        $this->app->container()->service(SettingsService::class)->saveContacts('en', new SettingsInput(phone: '+1 555 0100'), null, null);
        $this->actingAs($this->createUser('author@example.com', 'author'));

        $form = (string) $this->browser->get('/admin/content/contacts/new?locale=en')->getBody();
        foreach (['tab-address', 'name="phone"', 'contact-map', '+1 555 0100'] as $hidden) {
            self::assertStringNotContainsString($hidden, $form, $hidden);
        }
        $this->browser->post('/admin/content/contacts', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'phone' => '+1 000']);
        self::assertSame('+1 555 0100', $this->app->container()->service(SiteSettings::class)->of('en')->phone);

        self::assertSame(403, $this->browser->get('/admin/contact-map')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'address', 'address' => 'Eilat'])->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/contact-map/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertEquals(GoogleMap::query('Haifa'), $this->map());
    }

    public function testAnUnknownWayIsABadRequest(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        self::assertSame(400, $this->browser->post('/admin/contact-map', ['_csrf' => $this->csrfToken(), 'method' => 'query', 'query' => 'x'])->getStatusCode());
    }

    public function testTheContactsPageShowsTheMapInEveryLanguage(): void
    {
        self::assertStringNotContainsString('map-frame', (string) $this->browser->get('/contacts')->getBody(), 'no map yet');
        $this->app->container()->service(SettingsService::class)->saveMap(GoogleMap::query('Dizengoff 50, Tel Aviv', 15), null, null);

        foreach (['/contacts' => 'en', '/ru/contacts' => 'ru', '/he/contacts' => 'he'] as $path => $locale) {
            $response = $this->browser->get($path);
            $page = (string) $response->getBody();
            self::assertStringContainsString('<iframe class="map-frame" src="https://www.google.com/maps?q=Dizengoff+50%2C+Tel+Aviv&amp;z=15&amp;hl=' . $locale . '&amp;output=embed"', $page, $path);
            self::assertStringContainsString('<a href="https://www.google.com/maps/search/?api=1&amp;query=Dizengoff+50%2C+Tel+Aviv" rel="noopener" target="_blank">', $page, $path);
            self::assertStringContainsString(' https://www.google.com', $response->getHeaderLine('Content-Security-Policy'));
        }

        $this->app->container()->service(SettingsService::class)->saveMap(GoogleMap::embed('!1m2!1sabc'), null, null);
        $page = (string) $this->browser->get('/contacts')->getBody();
        self::assertStringContainsString('src="https://www.google.com/maps/embed?pb=!1m2!1sabc"', $page);
        self::assertStringNotContainsString('Open in Google Maps', $page, 'an embed without a centre has no link');
    }

    public function testTheContactPageMustBeARoutedPlainSingle(): void
    {
        $collections = $this->app->container()->service(Collections::class);

        self::assertSame('contacts', $this->app->container()->service(ContactPage::class)->collection?->name);
        self::assertNull(ContactPage::fromConfig(new Config([]), $collections)->collection);
        // A single that is not plain could keep a draft: an autosave would drop the contact edits.
        $notPlain = new Collections([Collection::make('about')->route('/about')->single()->fields([Field::text('body')])]);
        foreach ([[$collections, 'news'], [$collections, 'nothing'], [$collections, 42], [$notPlain, 'about']] as [$source, $name]) {
            try {
                ContactPage::fromConfig(new Config(['content' => ['contact_page' => $name]]), $source);
                self::fail('Expected a ConfigException');
            } catch (ConfigException $e) {
                self::assertStringContainsString('content.contact_page must name a plain() single() collection with a route', $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, string> the hidden inputs of the contacts entry's form
     */
    private function entryPost(): array
    {
        return ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => $this->contacts()->updatedAt->format('Y-m-d H:i:s')];
    }

    private function entryBody(): string
    {
        $entry = $this->app->container()->service(EntryRepository::class)->find($this->contacts()->id) ?? self::fail('No entry');

        return is_string($entry->data["body"] ?? null) ? $entry->data["body"] : "";
    }

    private function contactsForm(): string
    {
        return '/admin/content/contacts/' . $this->contacts()->id;
    }

    private function contacts(): Entry
    {
        return $this->contacts ??= $this->app->container()->service(EntryService::class)->create(
            $this->app->container()->service(Collections::class)->get('contacts'),
            'en',
            new EntryInput('Contacts', status: EntryStatus::Published, fields: ['body' => '<p>Old text</p>']),
            null,
            null,
        );
    }

    private function map(): ?GoogleMap
    {
        return $this->app->container()->service(SiteSettings::class)->of('en')->map;
    }
}
