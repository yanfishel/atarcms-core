<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client that answers with the responses (or throws the client exceptions) the test queued, in order, and
 * remembers the requests it was sent.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $answers;

    public function __construct(ResponseInterface|ClientExceptionInterface ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->answers) ?? throw new RuntimeException('No answer queued for ' . $request->getUri());
        if ($answer instanceof ClientExceptionInterface) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * A client exception, as a network failure would raise.
     */
    public static function networkError(string $message = 'Connection timed out'): ClientExceptionInterface
    {
        return new class ($message) extends RuntimeException implements ClientExceptionInterface {};
    }
}
