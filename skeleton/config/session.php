<?php

declare(strict_types=1);

return [
    // Seconds of inactivity after which a session ends (at least 1, at most absolute_timeout).
    'idle_timeout' => 7200,
    // Seconds after login after which a session ends regardless of activity.
    'absolute_timeout' => 43200,
];
