<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

/**
 * Where the contact form lives: config content.contact_form names the routed single() collection (the skeleton's
 * "contacts") whose page shows it and whose address receives it (POST, route "messages.send"). Without the key the
 * site has no contact form. Checked at boot.
 */
final readonly class ContactForm
{
    public const string ROUTE = 'messages.send';

    private function __construct(public ?Collection $page) {}

    /**
     * @throws ConfigException unless the key is missing or names a single() collection with a literal route
     */
    public static function fromConfig(Config $config, Collections $collections): self
    {
        $name = $config->get('content.contact_form');
        if ($name === null || $name === '') {
            return new self(null);
        }
        $collection = is_string($name) ? $collections->find($name) : null;
        if ($collection === null || !$collection->single || $collection->route === null || str_contains($collection->route, '{')) {
            throw new ConfigException(sprintf(
                'content.contact_form must name a single() collection with a route (the page that shows the form), got %s',
                is_string($name) ? '"' . $name . '"' : get_debug_type($name),
            ));
        }

        return new self($collection);
    }
}
