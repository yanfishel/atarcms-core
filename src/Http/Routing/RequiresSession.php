<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

/**
 * Marks route middleware that needs the session or the current user (RequireAuthentication, RequirePermission).
 * A stateless route can never carry such middleware.
 */
interface RequiresSession {}
