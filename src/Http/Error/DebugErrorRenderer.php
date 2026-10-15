<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class DebugErrorRenderer implements ErrorRenderer
{
    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        $sections = '';
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $sections .= sprintf(
                '<section><h2>%s</h2><p>%s</p><p>%s:%d</p><pre>%s</pre></section>',
                $this->escape($current::class),
                $this->escape($current->getMessage()),
                $this->escape($current->getFile()),
                $current->getLine(),
                $this->escape($current->getTraceAsString()),
            );
        }

        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%d %s</title></head>'
            . '<body><h1>%d %s</h1><p>%s %s</p>%s</body></html>',
            $status,
            $this->escape(PlainErrorPage::reason($status)),
            $status,
            $this->escape(PlainErrorPage::reason($status)),
            $this->escape($request->getMethod()),
            $this->escape((string) $request->getUri()),
            $sections,
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
