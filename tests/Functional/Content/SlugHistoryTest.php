<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Support\Cast;

/**
 * The previous slugs of published entries and of terms answer 301 to the current address (a renamed article keeps
 * its links); a moved entry or section needs nothing (the last segment finds it, the chain is corrected). The current
 * slugs always win over the history, which is read only when nothing else answers.
 */
final class SlugHistoryTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en', 'ru']));
    }

    public function testARenamedArticleSendsItsOldAddressToTheNewOne(): void
    {
        $entry = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->rename($entry, 'world');

        $response = $this->browser->get('/articles/hello?utm=x');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/articles/world?utm=x', $response->getHeaderLine('Location'), 'the query is kept');
        self::assertSame(200, $this->browser->get('/articles/world')->getStatusCode());
    }

    public function testEachLanguageKeepsItsOwnHistory(): void
    {
        $entry = $this->entry('articles', 'Привет', ['slug' => 'privet', 'locale' => 'ru']);
        $this->rename($entry, 'mir');

        self::assertSame('/ru/articles/mir', $this->browser->get('/ru/articles/privet')->getHeaderLine('Location'));
        self::assertSame(404, $this->browser->get('/articles/privet')->getStatusCode(), 'not in another language');
    }

    public function testEveryOldSlugOfAChainLeadsToTheCurrentOne(): void
    {
        $entry = $this->rename($this->rename($this->entry('articles', 'A', ['slug' => 'first']), 'second'), 'third');

        self::assertSame('/articles/third', $this->browser->get('/articles/first')->getHeaderLine('Location'));
        self::assertSame('/articles/third', $this->browser->get('/articles/second')->getHeaderLine('Location'));
        $this->rename($entry, 'first');
        self::assertSame(200, $this->browser->get('/articles/first')->getStatusCode(), 'renamed back: the slug is current again');
        self::assertSame('/articles/first', $this->browser->get('/articles/third')->getHeaderLine('Location'));
    }

    public function testADraftHasNoAddressToKeep(): void
    {
        $entry = $this->entry('articles', 'Draft', ['slug' => 'draft-one', 'status' => EntryStatus::Draft]);
        $this->rename($entry, 'draft-two');

        self::assertSame(404, $this->browser->get('/articles/draft-one')->getStatusCode());
    }

    public function testAnEntryThatIsNoLongerPublicIsNotRedirectedTo(): void
    {
        $entry = $this->rename($this->entry('articles', 'Gone', ['slug' => 'gone']), 'gone-now');
        $this->app->container()->service(EntryService::class)->update($entry, new EntryInput(...[...(array) EntryInput::fromEntry($entry), 'status' => EntryStatus::Draft]), null, null);

        self::assertSame(404, $this->browser->get('/articles/gone')->getStatusCode());
    }

    public function testACurrentSlugWinsOverAnOldOne(): void
    {
        $this->rename($this->entry('articles', 'Old', ['slug' => 'taken']), 'moved-on');
        $this->entry('articles', 'New', ['slug' => 'taken']);

        self::assertSame(200, $this->browser->get('/articles/taken')->getStatusCode(), 'the new article answers');
    }

    public function testADeletedEntryTakesItsHistoryWithIt(): void
    {
        $entry = $this->rename($this->entry('articles', 'Short', ['slug' => 'short']), 'short-lived');
        $this->app->container()->service(EntryService::class)->delete($entry, null, null);

        self::assertSame(404, $this->browser->get('/articles/short')->getStatusCode());
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM slug_history')));
    }

    public function testARenamedStoryKeepsItsSectionAndAPageAtTheRootIsRenamedToo(): void
    {
        $news = $this->term('sections', 'Daily', ['slug' => 'daily']);
        $story = $this->entry('stories', 'Story', ['slug' => 'story', 'terms' => ['sections' => [$news->id]]]);
        $this->rename($story, 'tale');
        $info = $this->entry('info', 'About', ['slug' => 'about']);
        $this->rename($info, 'about-us');

        self::assertSame('/daily/tale', $this->browser->get('/daily/story')->getHeaderLine('Location'));
        self::assertSame('/about-us', $this->browser->get('/about')->getHeaderLine('Location'));
    }

    public function testALiveSectionWinsOverAPageThatOnceHadItsSlug(): void
    {
        $this->rename($this->entry('info', 'Team', ['slug' => 'team']), 'people');
        $this->term('sections', 'Team', ['slug' => 'team']);

        self::assertSame(200, $this->browser->get('/team')->getStatusCode(), 'the section, not a redirect to /people');
    }

    public function testARenamedTagSendsItsOldPageToTheNewOne(): void
    {
        $tag = $this->term('tags', 'PHP', ['slug' => 'php']);
        $this->renameTerm($tag, 'php-8');

        self::assertSame('/tags/php-8', $this->browser->get('/tags/php')->getHeaderLine('Location'));
        self::assertSame(301, $this->browser->get('/tags/php')->getStatusCode());
    }

    public function testARenamedSectionKeepsTheAddressesOfItsArticles(): void
    {
        $news = $this->term('sections', 'Daily', ['slug' => 'daily']);
        $this->entry('stories', 'Story', ['slug' => 'story', 'terms' => ['sections' => [$news->id]]]);
        $this->renameTerm($news, 'weekly');

        self::assertSame('/weekly/story', $this->browser->get('/daily/story')->getHeaderLine('Location'), 'the story is found by its slug, its section corrected');
        self::assertSame('/weekly', $this->browser->get('/daily')->getHeaderLine('Location'), 'the section page itself');
    }

    private function rename(Entry $entry, string $slug): Entry
    {
        return $this->app->container()->service(EntryService::class)->update($entry, new EntryInput(...[...(array) EntryInput::fromEntry($entry), 'slug' => $slug]), null, null);
    }

    private function renameTerm(Term $term, string $slug): Term
    {
        return $this->app->container()->service(TermService::class)->update($term, new TermInput($term->name, $slug, $term->parentId, $term->position), null, null);
    }
}
