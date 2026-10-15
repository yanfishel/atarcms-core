<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Cli\Input;

/**
 * What bin/atar doctor was asked to do beyond the local checks.
 */
final readonly class DoctorOptions
{
    public function __construct(
        public bool $json = false,
        public bool $strict = false,
        /** Connect to the mail server (no mail is sent). */
        public bool $mail = false,
        /** Fetch the site over HTTP(S) and ask the web server's PHP through the one-time probe. */
        public bool $http = false,
        /** Connect to this base URL instead of app.url (the Host header stays app.url's). */
        public ?string $httpVia = null,
        /** A PEM CA file to verify the site's certificate against instead of the system CAs (Caddy's local CA in tests). */
        public ?string $httpCa = null,
    ) {}

    public static function fromInput(Input $input): self
    {
        $via = $input->option('http-via');
        $ca = $input->option('http-ca');

        return new self(
            json: $input->option('json') === true,
            strict: $input->option('strict') === true,
            mail: $input->option('mail') === true,
            http: $input->option('http') === true || is_string($via),
            httpVia: is_string($via) ? $via : null,
            httpCa: is_string($ca) ? $ca : null,
        );
    }
}
