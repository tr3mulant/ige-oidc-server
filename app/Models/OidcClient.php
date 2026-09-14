<?php

namespace App\Models;

use Admin9\OidcServer\Models\OidcClient as BaseOidcClient;

/**
 * `casts()` rather than `$casts`: the property would replace Passport's outright, taking
 * `redirect_uris` and `grant_types` with it. Eloquent merges the two (`HasAttributes:210`).
 */
class OidcClient extends BaseOidcClient
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_logout_redirect_uris' => 'array',
        ];
    }
}
