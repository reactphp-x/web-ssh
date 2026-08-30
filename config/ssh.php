<?php

declare(strict_types=1);

/**
 * Global SSH settings.
 *
 * default_identity: fallback private key path for key-based login
 * identity_candidates: tried in order when no explicit key is configured
 */
return [
    'default_identity' => getenv('SSH_IDENTITY_FILE') ?: null,
    'identity_candidates' => [
        '~/.ssh/id_ed25519',
        '~/.ssh/id_rsa',
    ],
    'connect_timeout' => max(1.0, (float) (getenv('SSH_CONNECT_TIMEOUT') ?: 10)),
];
