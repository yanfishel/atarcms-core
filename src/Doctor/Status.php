<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * The outcome of one doctor check: fine, worth a look, broken, or not run because something it needs is broken or
 * was not asked for.
 */
enum Status: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    /** The label of the text output, padded to one width. */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok  ',
            self::Warn => 'warn',
            self::Fail => 'FAIL',
            self::Skip => 'skip',
        };
    }
}
