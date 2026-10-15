<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\Content\EntryForm;
use AtarCms\Admin\Content\FormLayout;
use AtarCms\Admin\Content\FormTab;
use AtarCms\Content\Collection;
use AtarCms\Content\Field\Field;
use PHPUnit\Framework\TestCase;

final class FormLayoutTest extends TestCase
{
    public function testFieldsGoToTheirTabsAndTheBuiltInInputsToFixedOnes(): void
    {
        $news = Collection::make('news')
            ->route('/news/{slug}')
            ->archive('/news')
            ->taxonomies(['topics'])
            ->fields([
                Field::textarea('summary'),
                Field::image('cover')->tab('media'),
                Field::richText('body'),
                Field::date('event_date')->tab('settings'),
                Field::gallery('images')->tab('media'),
            ]);

        $layout = EntryForm::layout($news, EntryForm::defaults(), []);

        self::assertSame([
            'content' => [['title'], ['summary', 'body']],
            'media' => [[], ['cover', 'images']],
        ], self::describe($layout->main));
        self::assertSame([
            'settings' => [['terms', 'status', 'published_at'], ['event_date']],
            'seo' => [['share', 'meta_title', 'meta_description', 'slug'], []],
        ], self::describe($layout->side), 'the categories above the status');
        self::assertSame(['main' => $layout->main, 'side' => $layout->side], $layout->columns());
    }

    public function testTabsWithNothingOnThemAreLeftOutButBothColumnsStay(): void
    {
        $notes = EntryForm::layout(Collection::make('notes')->fields([Field::text('text')]), EntryForm::defaults(), []);
        self::assertSame(['content' => [['title'], ['text']]], self::describe($notes->main));
        self::assertSame(['settings' => [['status', 'published_at'], []], 'seo' => [['slug'], []]], self::describe($notes->side), 'no route: no search engine or sharing settings');

        $settings = EntryForm::layout(Collection::make('settings')->single()->fields([Field::text('tagline')]), EntryForm::defaults(), []);
        self::assertSame(['settings' => [['status', 'published_at'], []]], self::describe($settings->side), 'a single() without a route has no address');

        $pages = EntryForm::layout(Collection::make('pages')->route('/{slug}')->hierarchical(), EntryForm::defaults(), []);
        self::assertSame(['content' => [['title'], []]], self::describe($pages->main));
        self::assertSame(['status', 'published_at', 'parent'], $pages->side[0]->builtins);

        $home = EntryForm::layout(Collection::make('home')->route('/')->single(), EntryForm::defaults(), []);
        self::assertSame(['share', 'meta_title', 'meta_description'], $home->side[1]->builtins, 'a single() has no page address to edit');
    }

    public function testATagsFieldOnTheSettingsTabSitsAboveTheBuiltIns(): void
    {
        $news = Collection::make('news')
            ->route('/news/{slug}')
            ->fields([Field::text('summary'), Field::tags('tags')->tab('settings'), Field::date('event_date')->tab('settings')]);

        $settings = EntryForm::layout($news, EntryForm::defaults(), [])->side[0];

        self::assertSame('settings', $settings->name);
        self::assertSame(['tags'], array_map(static fn(Field $field): string => $field->name, $settings->leadFields));
        self::assertSame(['status', 'published_at'], $settings->builtins);
        self::assertSame(['event_date'], array_map(static fn(Field $field): string => $field->name, $settings->fields));
        $tagsOnContent = EntryForm::layout(Collection::make('news')->fields([Field::tags('tags')]), EntryForm::defaults(), []);
        self::assertSame(['content' => [['title'], ['tags']]], self::describe($tagsOnContent->main), 'only the Settings tab leads with the tags');
    }

    public function testErrorsAreCountedOnTheTabThatShowsTheInput(): void
    {
        $news = Collection::make('news')
            ->route('/news/{slug}')
            ->taxonomies(['topics'])
            ->fields([Field::text('summary'), Field::image('cover')->tab('media'), Field::tags('tags')->tab('settings')]);

        $layout = EntryForm::layout($news, EntryForm::defaults(), [
            'title' => 'validation.field.required',
            'summary' => 'validation.field.too_long',
            'cover' => 'validation.field.media',
            'published_at' => 'validation.field.datetime',
            'position' => 'validation.field.invalid',
            'terms.topics' => 'validation.terms.invalid',
            'terms.tags' => 'validation.term.comma',
            'slug' => 'validation.slug.taken',
            'meta_title' => 'validation.field.too_long',
            'entry' => 'validation.entry.stale',
        ]);

        self::assertSame(['content' => 2, 'media' => 1], self::errors($layout->main));
        self::assertSame(['settings' => 3, 'seo' => 2], self::errors($layout->side), 'published_at, the picked terms and the tags');
        self::assertSame(['position'], $layout->unplaced, 'news is not hierarchical: no input shows the position, and the stale-form error has its own alert');
    }

    /**
     * An error of an input the form does not show (a crafted post) counts on no tab; the form lists it above the tabs.
     */
    public function testErrorsOfInputsTheFormDoesNotShowAreUnplaced(): void
    {
        $settings = Collection::make('settings')->single()->taxonomies(['regions'])->fields([Field::text('tagline')]);
        $errors = [
            'slug' => 'validation.slug.taken',
            'parent_id' => 'validation.parent.invalid',
            'published_at' => 'validation.field.datetime',
            'meta_title' => 'validation.field.too_long',
            'terms.regions' => 'validation.terms.invalid',
            'terms.topics' => 'validation.terms.invalid',
            'tagline' => 'validation.field.too_long',
            'locale' => 'validation.locale.invalid',
        ];

        $layout = EntryForm::layout($settings, EntryForm::defaults(), $errors, canPublish: false);

        self::assertSame(['content' => 1], self::errors($layout->main));
        self::assertSame(['settings' => 1], self::errors($layout->side), 'the picked regions');
        self::assertSame(['slug', 'parent_id', 'published_at', 'meta_title', 'terms.topics'], $layout->unplaced);
        self::assertSame(['terms', 'status'], $layout->side[0]->builtins, 'without the right to publish there is no publication date');
    }

    public function testEveryBuiltInInputHasOneTab(): void
    {
        $all = Collection::make('pages')->route('/{slug}')->hierarchical()->taxonomies(['topics']);

        $layout = EntryForm::layout($all, EntryForm::defaults(), []);

        self::assertSame(['content' => [['title'], []]], self::describe($layout->main));
        self::assertSame([
            'settings' => [['terms', 'status', 'published_at', 'parent'], []],
            'seo' => [['share', 'meta_title', 'meta_description', 'slug'], []],
        ], self::describe($layout->side));
        self::assertSame([], $layout->unplaced);
    }

    public function testTheMediaTabCountsTheChosenFiles(): void
    {
        $news = Collection::make('news')->fields([
            Field::image('cover')->tab('media'),
            Field::gallery('images')->tab('media'),
            Field::file('attachment'),
            Field::text('caption')->tab('media'),
        ]);
        $values = EntryForm::defaults();
        $values['fields'] = ['cover' => 7, 'images' => '3, 4,x,4', 'attachment' => 9, 'caption' => '12'];

        $layout = EntryForm::layout($news, $values, []);

        self::assertSame('media', $layout->main[1]->name);
        self::assertSame(3, $layout->main[1]->count, 'the cover and two distinct gallery images; the file field is on the content tab');
        self::assertSame(0, $layout->main[0]->count);
    }

    public function testEachSlidesFieldIsATabOfItsOwnAfterTheOthers(): void
    {
        $news = Collection::make('news')->route('/news/{slug}')->fields([
            Field::slides('hero')->label('site.fields.hero')->tab('media'),
            Field::text('summary'),
            Field::image('cover')->tab('media'),
            Field::slides('promo')->tab('settings'),
        ]);
        $values = EntryForm::defaults();
        $values['fields'] = ['cover' => 7, 'hero' => [['image' => 3], ['image' => 4]]];

        $layout = EntryForm::layout($news, $values, ['hero' => 'validation.slides.image', 'promo' => 'validation.field.media']);

        self::assertSame([
            'content' => [['title'], ['summary']],
            'media' => [[], ['cover']],
            'slides-hero' => [[], ['hero']],
            'slides-promo' => [[], ['promo']],
        ], self::describe($layout->main));
        self::assertSame(['content' => 0, 'media' => 0, 'slides-hero' => 1, 'slides-promo' => 1], self::errors($layout->main));
        self::assertSame(1, $layout->main[1]->count, 'the slides are not counted as chosen files of the media tab');
        self::assertSame('site.fields.hero', $layout->main[2]->field?->label);
        self::assertNull($layout->main[0]->field);
        self::assertSame(['settings', 'seo'], array_map(static fn(FormTab $tab): string => $tab->name, $layout->side));
    }

    public function testAPlainFormGetsTabsOnlyWithASlidesField(): void
    {
        $home = Collection::make('home')->route('/')->single()->plain()->fields([Field::richText('intro'), Field::slides('hero')]);
        $layout = EntryForm::layout($home, EntryForm::defaults(), []);
        self::assertSame(['content' => [[], ['intro']], 'slides-hero' => [[], ['hero']]], self::describe($layout->main));
        self::assertSame([], $layout->side);

        $plain = EntryForm::layout(Collection::make('home')->route('/')->single()->plain()->fields([Field::richText('intro')]), EntryForm::defaults(), []);
        self::assertSame(['content' => [[], ['intro']]], self::describe($plain->main));

        $onlySlides = EntryForm::layout(Collection::make('home')->route('/')->single()->plain()->fields([Field::slides('hero')]), EntryForm::defaults(), []);
        self::assertSame(['slides-hero' => [[], ['hero']]], self::describe($onlySlides->main), 'an empty content tab is left out');
    }

    public function testTheLayoutIsAValueObject(): void
    {
        $layout = new FormLayout([new FormTab('content', ['title'], [])], [new FormTab('settings', ['status'], [], 2)]);

        self::assertSame(2, $layout->side[0]->errors);
        self::assertSame(0, $layout->main[0]->count);
        self::assertSame([], $layout->unplaced);
        self::assertSame(['slug'], new FormLayout([], [], ['slug'])->unplaced);
    }

    /**
     * @param list<FormTab> $tabs
     *
     * @return array<string, array{list<string>, list<string>}>
     */
    private static function describe(array $tabs): array
    {
        $described = [];
        foreach ($tabs as $tab) {
            $described[$tab->name] = [$tab->builtins, array_map(static fn(Field $field): string => $field->name, $tab->fields)];
        }

        return $described;
    }

    /**
     * @param list<FormTab> $tabs
     *
     * @return array<string, int>
     */
    private static function errors(array $tabs): array
    {
        $errors = [];
        foreach ($tabs as $tab) {
            $errors[$tab->name] = $tab->errors;
        }

        return $errors;
    }
}
