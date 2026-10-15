<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\Avatar\AvatarDownloader;
use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Auth\Google\GoogleIdentityProvider;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\FakeGoogleIdentityProvider;
use AtarCms\Tests\Support\FakeHttpClient;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class GoogleLoginTest extends FunctionalTestCase
{
    use TempDirectory;

    private const string PHOTO = 'https://lh3.googleusercontent.com/a/ACg8oc=s96-c';

    private FakeGoogleIdentityProvider $google;

    private MemoryLogger $logger;

    private FakeHttpClient $http;

    protected function tearDown(): void
    {
        putenv('ATAR_GOOGLE_ALLOWED_DOMAINS');
        parent::tearDown();
    }

    protected function configureContainer(Container $container): void
    {
        $this->google = new FakeGoogleIdentityProvider();
        $container->set(GoogleIdentityProvider::class, $this->google);
        $this->logger = new MemoryLogger();
        $container->set(LoggerInterface::class, $this->logger);
        $this->http ??= new FakeHttpClient();
        $container->set(AvatarDownloader::class, new AvatarDownloader($this->http, new Psr17Factory()));
        $container->set(Avatars::class, new Avatars($this->tempDir() . '/avatars', new MediaConfig(), new ImageProcessor()));
    }

    public function testTheGoogleProfilePhotoBecomesTheAvatar(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->useHttp(new FakeHttpClient(new Response(200, ['Content-Type' => 'image/jpeg'], TestImages::jpeg(200, 150))));

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', 'sub-1', ['picture' => self::PHOTO]));

        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertSame(self::PHOTO, (string) $this->http->requests[0]->getUri());
        $version = $this->users()->find($user->id)?->avatarVersion;
        self::assertIsString($version);
        self::assertSame([96, 96, IMAGETYPE_WEBP], array_slice(getimagesize($this->tempDir() . '/avatars/' . $user->id . '.webp') ?: [], 0, 3));
        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true"><img class="admin-avatar-image" src="/admin/avatar/' . $user->id . '?v=' . $version . '" alt="" width="36" height="36"></span>', $page);
    }

    public function testAFailedPhotoNeitherBlocksTheSignInNorDropsThePreviousOne(): void
    {
        $user = $this->createUser('ann@example.com');
        $avatars = $this->app->container()->service(Avatars::class);
        $version = $avatars->save($user->id, TestImages::png(96, 96));
        $this->users()->setAvatarVersion($user->id, $version);
        $this->useHttp(new FakeHttpClient(FakeHttpClient::networkError(), new Response(200, [], TestImages::pdf())));

        foreach ([self::PHOTO, self::PHOTO, 'https://evil.example/photo.png'] as $picture) {
            $this->browser = new Browser($this->app);
            $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', 'sub-1', ['picture' => $picture]));
            self::assertSame('/admin', $response->getHeaderLine('Location'));
        }

        self::assertCount(2, $this->http->requests, 'a foreign address is never requested');
        self::assertSame($version, $this->users()->find($user->id)?->avatarVersion);
        self::assertSame($version, substr(hash_file('sha256', $avatars->path($user->id)) ?: '', 0, 12));
        $notices = array_values(array_filter($this->logger->records, static fn(array $record): bool => str_starts_with($record['message'], 'The Google profile photo')));
        self::assertCount(3, $notices);
    }

    public function testASignInWithoutAPictureLeavesTheAvatarAlone(): void
    {
        $user = $this->createUser('ann@example.com');

        $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com'));

        self::assertSame([], $this->http->requests);
        self::assertNull($this->users()->find($user->id)?->avatarVersion);
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">A</span>', (string) $this->browser->get('/admin')->getBody());
    }

    private function useHttp(FakeHttpClient $http): void
    {
        $this->http = $http;
        $this->app->container()->set(AvatarDownloader::class, new AvatarDownloader($http, new Psr17Factory()));
    }

    public function testKnownUserSignsInAndGoogleAccountIsBound(): void
    {
        $user = $this->createUser('ann@example.com');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('Ann@Example.com', 'sub-1'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->browser->get('/admin')->getStatusCode());
        $bound = $this->users()->find($user->id);
        self::assertSame('sub-1', $bound?->googleSub);
        self::assertNotNull($bound->lastLoginAt);
        self::assertSame('auth-code', $this->google->lastCode);
        self::assertSame('fake-verifier', $this->google->lastVerifier);
        self::assertSame(['auth.login'], $this->auditActions());
    }

    public function testLaterLoginsMatchBySubEvenIfEmailChanged(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->users()->bindGoogleSub($user->id, 'sub-1');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann.new@example.com', 'sub-1'));

        self::assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function testAccountBoundToAnotherGoogleAccountIsRejected(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->users()->bindGoogleSub($user->id, 'other-sub');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', 'sub-1'));

        self::assertSame('/admin/login?error=unknown_user', $response->getHeaderLine('Location'));
    }

    public function testAccentInsensitiveEmailFallbackIsRejected(): void
    {
        $user = $this->createUser('strasse@example.com');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('straße@example.com'));

        self::assertSame('/admin/login?error=unknown_user', $response->getHeaderLine('Location'));
        self::assertNull($this->users()->find($user->id)?->googleSub);
    }

    public function testUnknownAccountIsRejectedWithoutCreatingAUser(): void
    {
        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('stranger@example.com'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login?error=unknown_user', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame(['auth.login_failed'], $this->auditActions());
        self::assertSame(
            '{"method":"google","reason":"unknown_user","email":"stranger@example.com"}',
            $this->db->scalar('SELECT meta FROM audit_log'),
        );
        self::assertStringContainsString(
            'This Google account has no access to this site.',
            (string) $this->browser->get($response->getHeaderLine('Location'))->getBody(),
        );
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
    }

    public function testDisabledUserIsRejectedAndNotBound(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->users()->setStatus($user->id, UserStatus::Disabled);

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com'));

        self::assertSame('/admin/login?error=disabled', $response->getHeaderLine('Location'));
        self::assertNull($this->users()->find($user->id)?->googleSub);
        self::assertSame(['auth.login_failed'], $this->auditActions());
    }

    public function testUnverifiedEmailIsRejected(): void
    {
        $this->createUser('ann@example.com');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', overrides: ['email_verified' => false]));

        self::assertSame('/admin/login?error=email_unverified', $response->getHeaderLine('Location'));
        self::assertSame([], $this->auditActions(), 'no verified identity: logged, not audited');
    }

    public function testTokenForAnotherClientIsRejected(): void
    {
        $this->createUser('ann@example.com');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', overrides: ['aud' => 'someone-else']));

        self::assertSame('/admin/login?error=invalid_token', $response->getHeaderLine('Location'));
        self::assertSame([], $this->auditActions());
    }

    public function testTokenAuthorizedForAnotherClientIsRejected(): void
    {
        $this->createUser('ann@example.com');

        $response = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', overrides: ['azp' => 'someone-else']));

        self::assertSame('/admin/login?error=invalid_token', $response->getHeaderLine('Location'));
        self::assertNull($this->users()->findByEmail('ann@example.com')?->googleSub);
    }

    public function testForgedOrMissingStateIsRejected(): void
    {
        $this->createUser('ann@example.com');
        $this->google->claims = FakeGoogleIdentityProvider::claimsFor('ann@example.com');

        $withoutStart = $this->browser->get('/admin/login/google/callback?state=fake-state&code=x');
        self::assertSame('/admin/login?error=state_mismatch', $withoutStart->getHeaderLine('Location'));

        $this->browser->get('/admin/login/google');
        $forged = $this->browser->get('/admin/login/google/callback?state=evil&code=x');
        self::assertSame('/admin/login?error=state_mismatch', $forged->getHeaderLine('Location'));

        $replayed = $this->browser->get('/admin/login/google/callback?state=fake-state&code=x');
        self::assertSame('/admin/login?error=state_mismatch', $replayed->getHeaderLine('Location'), 'state is single-use');

        // Anyone can send these requests: they are logged, never written to audit_log.
        self::assertSame([], $this->auditActions());
        $notices = array_filter($this->logger->records, static fn(array $record): bool => $record['message'] === 'Google login failed: {message}');
        self::assertCount(3, $notices);
    }

    public function testProviderErrorIsRejected(): void
    {
        $this->browser->get('/admin/login/google');

        $response = $this->browser->get('/admin/login/google/callback?state=fake-state&error=access_denied');

        self::assertSame('/admin/login?error=provider_error', $response->getHeaderLine('Location'));
        self::assertSame([], $this->auditActions());
    }

    public function testRedirectTargetIsKeptButExternalTargetsAreNot(): void
    {
        $this->createUser('ann@example.com');
        $claims = FakeGoogleIdentityProvider::claimsFor('ann@example.com');

        self::assertSame('/admin?tab=1', $this->signIn($claims, '?redirect=%2Fadmin%3Ftab%3D1')->getHeaderLine('Location'));

        $this->browser = new Browser($this->app);
        self::assertSame('/admin', $this->signIn($claims, '?redirect=%2F%2Fevil.example')->getHeaderLine('Location'));
    }

    public function testSessionIdChangesOnLogin(): void
    {
        $this->createUser('ann@example.com');
        $this->google->claims = FakeGoogleIdentityProvider::claimsFor('ann@example.com');

        $this->browser->get('/admin/login/google');
        $before = $this->browser->cookie(SessionMiddleware::SECURE_COOKIE);
        $this->browser->get('/admin/login/google/callback?state=fake-state&code=auth-code');

        self::assertNotNull($before);
        self::assertNotSame($before, $this->browser->cookie(SessionMiddleware::SECURE_COOKIE));
        self::assertArrayNotHasKey($before, $this->sessions->sessions);
    }

    public function testAllowedDomainsRestrictLogin(): void
    {
        putenv('ATAR_GOOGLE_ALLOWED_DOMAINS=example.com, example.org');
        $this->bootApp();
        $this->createUser('ann@example.com');

        $personal = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com'));
        self::assertSame('/admin/login?error=domain_not_allowed', $personal->getHeaderLine('Location'));

        $workspace = $this->signIn(FakeGoogleIdentityProvider::claimsFor('ann@example.com', overrides: ['hd' => 'Example.COM']));
        self::assertSame('/admin', $workspace->getHeaderLine('Location'));
    }

    public function testLoginPageLinksToGoogleWithSafeRedirectOnly(): void
    {
        self::assertStringContainsString(
            'href="/admin/login/google?redirect=%2Fadmin%2Fusers"',
            (string) $this->browser->get('/admin/login?redirect=%2Fadmin%2Fusers')->getBody(),
        );
        self::assertStringContainsString(
            'href="/admin/login/google"',
            (string) $this->browser->get('/admin/login?redirect=%2F%2Fevil.example')->getBody(),
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signIn(array $claims, string $query = ''): ResponseInterface
    {
        $this->google->claims = $claims;
        $start = $this->browser->get('/admin/login/google' . $query);
        self::assertSame(302, $start->getStatusCode());
        self::assertSame('https://accounts.google.test/auth?state=fake-state', $start->getHeaderLine('Location'));

        return $this->browser->get('/admin/login/google/callback?state=fake-state&code=auth-code');
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }
}
