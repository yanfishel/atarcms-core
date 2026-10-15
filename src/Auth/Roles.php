<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

final class Roles
{
    public const array DEFAULTS = [
        'admin' => ['label' => 'admin.roles.admin', 'permissions' => ['*']],
        'editor' => ['label' => 'admin.roles.editor', 'permissions' => ['entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        'author' => ['label' => 'admin.roles.author', 'permissions' => ['entries.*.view', 'entries.*.create', 'entries.*.edit', 'media.upload']],
    ];

    /** @var array<string, array{label: string, permissions: list<string>}> */
    private readonly array $roles;

    /**
     * @param array<mixed> $roles
     */
    public function __construct(array $roles)
    {
        $parsed = [];
        foreach ($roles as $name => $definition) {
            if (!is_string($name) || preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
                throw new ConfigException(sprintf('Invalid role name "%s"; use lowercase letters, digits, "_" or "-"', $name));
            }
            $label = is_array($definition) ? ($definition['label'] ?? $name) : null;
            $permissions = is_array($definition) ? ($definition['permissions'] ?? null) : null;
            if (!is_string($label) || !is_array($permissions) || !array_is_list($permissions)) {
                throw new ConfigException(sprintf('Role "%s" must be ["label" => string, "permissions" => list<string>]', $name));
            }
            $list = [];
            foreach ($permissions as $permission) {
                if (!is_string($permission) || !PermissionPattern::isValid($permission)) {
                    throw new ConfigException(sprintf(
                        'Role "%s" has an invalid permission %s',
                        $name,
                        is_string($permission) ? '"' . $permission . '"' : get_debug_type($permission),
                    ));
                }
                $list[] = $permission;
            }
            $parsed[$name] = ['label' => $label, 'permissions' => $list];
        }
        if ($parsed === []) {
            throw new ConfigException('At least one role must be defined');
        }
        $this->roles = $parsed;
    }

    public static function fromConfig(Config $config): self
    {
        $roles = $config->array('roles');

        return new self($roles === [] ? self::DEFAULTS : $roles);
    }

    public function exists(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    /**
     * @return array<string, string> role => label (text or a translation key; templates show it through label()), in config order
     */
    public function labels(): array
    {
        return array_map(static fn(array $role): string => $role['label'], $this->roles);
    }

    public function label(string $role): string
    {
        return $this->roles[$role]['label'] ?? $role;
    }

    public function allows(string $role, string $permission): bool
    {
        foreach ($this->roles[$role]['permissions'] ?? [] as $pattern) {
            if (PermissionPattern::matches($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $actorRole may hand out $role: every permission pattern of $role must be allowed by $actorRole.
     */
    public function grants(string $actorRole, string $role): bool
    {
        if (!isset($this->roles[$role])) {
            return false;
        }
        foreach ($this->roles[$role]['permissions'] as $pattern) {
            if (!$this->allows($actorRole, $pattern)) {
                return false;
            }
        }

        return true;
    }
}
