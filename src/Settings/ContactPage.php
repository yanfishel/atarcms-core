<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

/**
 * The page that holds the site's contact details: config content.contact_page names the routed plain() single()
 * collection (the skeleton's "contacts") whose entry form edits the address, opening hours, phone, e-mail, messengers, social
 * links and the site's map (GoogleMap). Without the key the site has no map (no admin UI, the theme helper
 * contactMap() returns null) and those settings are written only by bin/atar settings:import. Checked at boot.
 */
final readonly class ContactPage
{
    private function __construct(public ?Collection $collection) {}

    /**
     * @throws ConfigException unless the key is missing or names a plain() single() collection with a literal route
     */
    public static function fromConfig(Config $config, Collections $collections): self
    {
        $name = $config->get('content.contact_page');
        if ($name === null || $name === '') {
            return new self(null);
        }
        $collection = is_string($name) ? $collections->find($name) : null;
        // Plain: always published, never an autosaved draft, so a save of its form is always the one that writes the
        // contact details with it.
        if ($collection === null || !$collection->single || !$collection->plain || $collection->route === null || str_contains($collection->route, '{')) {
            throw new ConfigException(sprintf(
                'content.contact_page must name a plain() single() collection with a route (the page with the contact details), got %s',
                is_string($name) ? '"' . $name . '"' : get_debug_type($name),
            ));
        }

        return new self($collection);
    }

    public function is(Collection $collection): bool
    {
        return $this->collection !== null && $this->collection->name === $collection->name;
    }
}
