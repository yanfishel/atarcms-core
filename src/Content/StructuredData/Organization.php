<?php

declare(strict_types=1);

namespace AtarCms\Content\StructuredData;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Settings\Settings;

/**
 * The site as a schema.org organization: the home page's graph holds its full node (from the site settings of the
 * page's language), and an article's author and publisher name the same node by its @id. Its type is
 * content.organization_type (default "Organization"; a local business sets "LocalBusiness" or a subtype such as
 * "Restaurant" or "Dentist").
 */
final readonly class Organization
{
    public const string DEFAULT_TYPE = 'Organization';

    /**
     * @param string $type a schema.org type name
     * @param string $appUrl the site root without its trailing slash (Config::appUrl())
     */
    public function __construct(
        public string $type,
        private string $appUrl,
    ) {}

    /**
     * @throws ConfigException unless content.organization_type is a schema.org type name ("Store", "LocalBusiness")
     */
    public static function fromConfig(Config $config): self
    {
        $type = $config->string('content.organization_type', self::DEFAULT_TYPE);
        if (preg_match('/^[A-Z][A-Za-z]+$/D', $type) !== 1) {
            throw new ConfigException(sprintf('content.organization_type must be a schema.org type name such as "Organization" or "LocalBusiness", got "%s"', $type));
        }

        return new self($type, $config->appUrl());
    }

    public function id(): string
    {
        return $this->appUrl . '/#organization';
    }

    /**
     * The organization as an article's author or publisher: its type, @id, name and URL.
     *
     * @return array<string, mixed>
     */
    public function reference(string $name): array
    {
        return ['@type' => $this->type, '@id' => $this->id(), 'name' => $name, 'url' => $this->appUrl . '/'];
    }

    /**
     * The full node with what the settings hold: the logo, phone, e-mail, the address on one line (schema.org takes
     * text; it is not split into street and city), the map's coordinates and the social pages as sameAs. The opening
     * hours are left out: they are free text, and openingHours wants "Mo-Fr 09:00-18:00".
     *
     * @param string|null $logo the absolute URL of the site's logo (or icon)
     *
     * @return array<string, mixed>
     */
    public function node(string $name, ?string $logo, Settings $site): array
    {
        $node = $this->reference($name);
        if ($logo !== null) {
            $node['logo'] = $logo;
        }
        if (($site->phone ?? '') !== '') {
            $node['telephone'] = $site->phone;
        }
        if (($site->email ?? '') !== '') {
            $node['email'] = $site->email;
        }
        $address = implode(', ', array_values(array_filter(array_map('trim', explode("\n", $site->address ?? '')), static fn(string $line): bool => $line !== '')));
        if ($address !== '') {
            $node['address'] = $address;
        }
        $point = $site->map?->coordinates();
        if ($point !== null) {
            $node['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $point[0], 'longitude' => (float) $point[1]];
        }
        if ($site->socialLinks !== []) {
            $node['sameAs'] = array_map(static fn($link): string => $link->url, $site->socialLinks);
        }

        return $node;
    }
}
