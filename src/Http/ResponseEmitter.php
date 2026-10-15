<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Psr\Http\Message\ResponseInterface;

final class ResponseEmitter
{
    private const int CHUNK_SIZE = 8192;

    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if (!headers_sent()) {
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ), true, $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                $replace = strcasecmp((string) $name, 'Set-Cookie') !== 0;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }
        }
        if (!$withBody) {
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }
}
