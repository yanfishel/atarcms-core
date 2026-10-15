<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Doctor;

use AtarCms\Cli\Output;
use AtarCms\Database\Migrator;
use AtarCms\Doctor\CheckResult;
use AtarCms\Doctor\DatabaseChecks;
use AtarCms\Doctor\Doctor;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\MailChecks;
use AtarCms\Doctor\Status;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\FakePlatform;
use AtarCms\Tests\Support\TempDirectory;
use RuntimeException;

/**
 * The database checks against the test database, and the secrets that must never reach the doctor's output.
 */
final class DoctorDatabaseTest extends DatabaseTestCase
{
    use TempDirectory;

    private const string SITE = __DIR__ . '/../../Fixtures/site';

    protected function tearDown(): void
    {
        foreach (['DB_PASSWORD', 'MAIL_DSN', 'ATAR_GOOGLE_CLIENT_ID', 'ATAR_GOOGLE_CLIENT_SECRET'] as $name) {
            putenv($name);
        }
        putenv('DB_PASSWORD=' . self::config()['password']);
    }

    public function testAFreshDatabaseNeedsItsMigrationsThenAnAdministrator(): void
    {
        $results = $this->check();
        self::assertSame(Status::Ok, $results['db.connect']->status);
        self::assertSame(Status::Ok, $results['db.server']->status, $results['db.server']->message);
        self::assertSame(Status::Ok, $results['db.fulltext']->status);
        self::assertSame(Status::Fail, $results['db.migrations']->status);
        self::assertStringContainsString('bin/atar migrate', (string) $results['db.migrations']->fix);
        self::assertSame(Status::Skip, $results['db.admin']->status);
        self::assertSame(0, Cast::int($this->db->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'")), 'the doctor writes nothing');

        $this->context()->app()?->container()->service(Migrator::class)->migrate();
        $results = $this->check();
        self::assertSame(Status::Ok, $results['db.migrations']->status);
        self::assertSame(Status::Warn, $results['db.admin']->status);
        self::assertStringContainsString('bin/atar user:create', (string) $results['db.admin']->fix);

        $this->db->insert('users', ['email' => 'a@example.com', 'role' => 'admin', 'status' => 'active', 'created_at' => '2026-10-05 10:00:00']);
        self::assertSame(Status::Ok, $this->check()['db.admin']->status);
    }

    public function testAWrongPasswordFailsWithoutPrintingIt(): void
    {
        putenv('DB_PASSWORD=S3cret-Pa55word');

        $results = $this->check();

        self::assertSame(Status::Fail, $results['db.connect']->status);
        self::assertSame(Status::Skip, $results['db.migrations']->status);
        self::assertStringNotContainsString('S3cret-Pa55word', $this->fullReport());
    }

    public function testNoSecretReachesTheReport(): void
    {
        putenv('MAIL_DSN=smtp://mailer:Mail-Pa55word@mail.example.com:587');
        putenv('ATAR_GOOGLE_CLIENT_ID=client-id');
        putenv('ATAR_GOOGLE_CLIENT_SECRET=Google-S3cret');

        $text = $this->fullReport();

        self::assertStringContainsString('smtp://mail.example.com:587', $text);
        foreach (['Mail-Pa55word', 'Google-S3cret', 'mailer:'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testMailChecks(): void
    {
        $connected = [];
        $mail = new MailChecks(static function (string $dsn) use (&$connected): void {
            $connected[] = $dsn;
            throw new RuntimeException('Failed to authenticate on SMTP server with username "mailer" and password "Mail-Pa55word"');
        });

        putenv('MAIL_DSN=');
        $results = $this->ids($mail->run($this->context(new DoctorOptions(mail: true))));
        self::assertSame(Status::Warn, $results['mail.config']->status);
        self::assertSame(Status::Skip, $results['mail.connect']->status);

        putenv('MAIL_DSN=not a dsn');
        self::assertSame(Status::Fail, $this->ids($mail->run($this->context()))['mail.config']->status);

        putenv('MAIL_DSN=smtp://mailer:Mail-Pa55word@mail.example.com:587');
        $results = $this->ids($mail->run($this->context()));
        self::assertSame(Status::Ok, $results['mail.config']->status);
        self::assertSame(Status::Skip, $results['mail.connect']->status, 'only with --mail');
        self::assertSame([], $connected);

        $results = $this->ids($mail->run($this->context(new DoctorOptions(mail: true))));
        self::assertSame(Status::Fail, $results['mail.connect']->status);
        self::assertStringNotContainsString('Mail-Pa55word', $results['mail.connect']->message);
        self::assertCount(1, $connected);

        // A site whose config reads Google's client from the environment, like the skeleton's.
        $this->writeFile('google/config/app.php', "<?php\nreturn ['url' => 'https://example.com'];\n");
        $this->writeFile('google/config/auth.php', (string) file_get_contents(__DIR__ . '/../../../skeleton/config/auth.php'));
        $google = new DoctorContext(new Paths($this->tempDir() . '/google'), new FakePlatform(), new DoctorOptions());
        putenv('ATAR_GOOGLE_CLIENT_ID=client-id');
        self::assertSame(Status::Fail, $this->ids($mail->run($google))['google.config']->status);
        putenv('ATAR_GOOGLE_CLIENT_SECRET=secret');
        $google = new DoctorContext(new Paths($this->tempDir() . '/google'), new FakePlatform(), new DoctorOptions());
        self::assertSame(Status::Ok, $this->ids($mail->run($google))['google.config']->status);
    }

    /**
     * @return array<string, CheckResult>
     */
    private function check(): array
    {
        return $this->ids((new DatabaseChecks())->run($this->context()));
    }

    /**
     * @param list<CheckResult> $results
     *
     * @return array<string, CheckResult>
     */
    private function ids(array $results): array
    {
        $byId = [];
        foreach ($results as $result) {
            $byId[$result->id] = $result;
        }

        return $byId;
    }

    private function context(?DoctorOptions $options = null): DoctorContext
    {
        return new DoctorContext(new Paths(self::SITE, $this->tempDir() . '/storage'), new FakePlatform(), $options ?? new DoctorOptions());
    }

    private function fullReport(?DoctorOptions $options = null): string
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
        Doctor::standard()->report($this->context($options), new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
    }
}
