<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;

/**
 * The configured taxonomies (config/taxonomies.php). Collections checks the references to them.
 */
final class Taxonomies
{
    /** @var array<string, Taxonomy> */
    private readonly array $taxonomies;

    /**
     * @param array<mixed> $taxonomies Taxonomy objects, optionally keyed by their names
     */
    public function __construct(array $taxonomies)
    {
        $parsed = [];
        foreach ($taxonomies as $key => $taxonomy) {
            if (!$taxonomy instanceof Taxonomy) {
                throw new ConfigException(sprintf('config/taxonomies.php must return Taxonomy objects, got %s', get_debug_type($taxonomy)));
            }
            if (is_string($key) && $key !== $taxonomy->name) {
                throw new ConfigException(sprintf('Taxonomy "%s" is listed under the key "%s"; use its name as the key', $taxonomy->name, $key));
            }
            if (isset($parsed[$taxonomy->name])) {
                throw new ConfigException(sprintf('Taxonomy "%s" is defined twice', $taxonomy->name));
            }
            if ($taxonomy->redirectToFirstChild && (!$taxonomy->hierarchical || $taxonomy->route === null)) {
                throw new ConfigException(sprintf('Taxonomy "%s": redirectToFirstChild() needs a hierarchical() taxonomy with a route()', $taxonomy->name));
            }
            $parsed[$taxonomy->name] = $taxonomy;
        }
        $this->taxonomies = $parsed;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->array('taxonomies'));
    }

    public function get(string $name): Taxonomy
    {
        return $this->taxonomies[$name] ?? throw new InvalidArgumentException(sprintf('Unknown taxonomy "%s"', $name));
    }

    public function find(string $name): ?Taxonomy
    {
        return $this->taxonomies[$name] ?? null;
    }

    /**
     * The taxonomy routed at the root ("/{slug}", such as the sections), if any; Collections allows only one.
     */
    public function root(): ?Taxonomy
    {
        foreach ($this->taxonomies as $taxonomy) {
            if ($taxonomy->isRoot()) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * @return array<string, Taxonomy> in config order
     */
    public function all(): array
    {
        return $this->taxonomies;
    }
}
