<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

/**
 * What PublicFormToken::check() found.
 */
enum PublicFormCheck
{
    case Valid;

    /** Sent within PublicFormToken::MIN_SECONDS of loading the form: a bot, treated as spam. */
    case TooFast;

    /** Older than PublicFormToken::MAX_SECONDS: the visitor is asked to send it again. */
    case Expired;

    /** Missing, broken, of another form or another site's key. */
    case Invalid;
}
