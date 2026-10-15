<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

final class PlainErrorPage
{
    private const array REASONS = [
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? ($status >= 500 ? 'Server Error' : 'Error');
    }

    public static function render(int $status): string
    {
        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%1$d %2$s</title></head>'
            . '<body><h1>%1$d %2$s</h1></body></html>',
            $status,
            self::reason($status),
        );
    }
}
