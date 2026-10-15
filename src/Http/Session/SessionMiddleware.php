<?php

declare(strict_types=1);

namespace AtarCms\Http\Session;

use AtarCms\Http\Error\ErrorMiddleware;
use AtarCms\Http\RequestPath;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Loads the session, runs the handler and saves the session. An error inside the handler is rendered here (through
 * ErrorMiddleware::respond()), so a logout or id change before it still takes effect and the error page of a
 * session gets "no-store".
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const string SECURE_COOKIE = '__Host-atar';
    public const string LOCAL_COOKIE = 'atar';

    /** An unchanged session is re-saved (to refresh the idle timer) at most this often. */
    private const int TOUCH_INTERVAL = 300;

    private readonly int $idleTimeout;

    private readonly int $absoluteTimeout;

    private readonly bool $local;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly Clock $clock,
        Config $config,
        private readonly ErrorMiddleware $errors,
    ) {
        $this->idleTimeout = $config->int('session.idle_timeout', 7200);
        $this->absoluteTimeout = $config->int('session.absolute_timeout', 43200);
        if ($this->idleTimeout < 1 || $this->absoluteTimeout < 1 || $this->idleTimeout > $this->absoluteTimeout) {
            throw new ConfigException(sprintf(
                'session.idle_timeout (%d) and session.absolute_timeout (%d) must be at least 1 second, and the idle timeout cannot exceed the absolute one',
                $this->idleTimeout,
                $this->absoluteTimeout,
            ));
        }
        $this->local = $config->environment() === Config::LOCAL;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (RouteMatch::of($request)?->route->isStateless() === true) {
            return $this->stateless($request, $handler);
        }
        $secure = !$this->local || $request->getUri()->getScheme() === 'https';
        $cookieName = $secure ? self::SECURE_COOKIE : self::LOCAL_COOKIE;
        $cookies = $request->getCookieParams();
        $cookieSent = array_key_exists($cookieName, $cookies);
        $cookieId = self::validId($cookies[$cookieName] ?? null);
        $now = $this->clock->now()->getTimestamp();

        $record = null;
        if ($cookieId !== null) {
            $record = SessionRecord::fromPayload($this->storage->read($cookieId));
            if ($record !== null && ($now - $record->lastSeen > $this->idleTimeout || $now - $record->createdAt > $this->absoluteTimeout)) {
                $this->storage->destroy($cookieId);
                $record = null;
            }
        }
        $session = $record === null ? new Session() : new Session($cookieId, $record->data);
        $request = $request->withAttribute(Session::class, $session);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            $response = $this->errors->respond($error, $request);
        }

        return $this->persist($session, $record, $response, $now, $cookieName, $secure, $cookieSent);
    }

    /**
     * A stateless route gets a fresh, empty session that is never loaded or saved: no storage access, no cookie,
     * no Cache-Control change. Writing to it would be lost silently, so it is an error.
     */
    private function stateless(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = new Session();
        $response = $handler->handle($request->withAttribute(Session::class, $session));
        if ($session->isDirty() || $session->id() !== null || $session->isInvalidated() || $session->needsNewId()) {
            throw new LogicException(sprintf('The stateless route "%s" must not write to the session', RequestPath::of($request)->raw));
        }

        return $response;
    }

    private function persist(
        Session $session,
        ?SessionRecord $record,
        ResponseInterface $response,
        int $now,
        string $cookieName,
        bool $secure,
        bool $cookieSent,
    ): ResponseInterface {
        $currentId = $session->id();
        $keepsId = $currentId !== null && !$session->isInvalidated() && !$session->needsNewId();
        if ($currentId !== null && !$keepsId) {
            $this->storage->destroy($currentId);
        }

        $data = $session->all();
        if ($data === [] && !$keepsId) {
            return $cookieSent ? $this->noStore($response->withAddedHeader('Set-Cookie', $this->expiredCookie($cookieName, $secure))) : $response;
        }
        if ($keepsId && $record !== null && !$session->isDirty() && $now - $record->lastSeen < self::TOUCH_INTERVAL) {
            return $this->noStore($response);
        }

        $id = $keepsId ? $currentId : bin2hex(random_bytes(32));
        $createdAt = $keepsId && $record !== null ? $record->createdAt : $now;
        $this->storage->write($id, (new SessionRecord($data, $createdAt, $now))->toPayload());

        if (!$keepsId) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($cookieName, $id, $secure));
        }

        return $this->noStore($response);
    }

    private function cookie(string $name, string $id, bool $secure): string
    {
        return sprintf('%s=%s; Path=/; HttpOnly; SameSite=Lax%s', $name, $id, $secure ? '; Secure' : '');
    }

    private function expiredCookie(string $name, bool $secure): string
    {
        return sprintf('%s=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax%s', $name, $secure ? '; Secure' : '');
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->hasHeader('Cache-Control') ? $response : $response->withHeader('Cache-Control', 'no-store, private');
    }

    private static function validId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
}
