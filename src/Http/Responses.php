<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

final class Responses
{
    public function __construct(private readonly Psr17Factory $factory) {}

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->body($html, $status, 'text/html; charset=utf-8');
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->body($text, $status, 'text/plain; charset=utf-8');
    }

    public function markdown(string $markdown): ResponseInterface
    {
        return $this->body($markdown, 200, 'text/markdown; charset=utf-8');
    }

    /**
     * JSON with <, >, &, ' and " escaped as \u sequences, so the body is also safe if a browser sniffs it as HTML.
     *
     * @param array<mixed> $data
     */
    public function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->body(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $status,
            'application/json; charset=utf-8',
        );
    }

    public function redirect(string $location, int $status = 302): ResponseInterface
    {
        return $this->factory->createResponse($status)->withHeader('Location', $location);
    }

    public function xml(string $xml, string $type = 'application/xml'): ResponseInterface
    {
        return $this->body($xml, 200, $type . '; charset=utf-8');
    }

    private function body(string $body, int $status, string $contentType): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->factory->createStream($body));
    }
}
