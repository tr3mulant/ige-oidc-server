<?php

declare(strict_types=1);

namespace App\Services;

use Admin9\OidcServer\Services\IdTokenService;
use Lcobucci\JWT\ClaimsFormatter;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Builder;

/**
 * Stamps the `kid` the package omits while `jwks.json` publishes one.
 *
 * Without it a client cannot tell which published key to verify against, so a rotation
 * can never overlap two keys — every token has to die at once instead.
 *
 * Swapping the builder factory keeps claim and lifetime logic in the package, where
 * upgrades still reach it.
 */
class OidcIdTokenService extends IdTokenService
{
    protected function getJwtConfig(): Configuration
    {
        return parent::getJwtConfig()->withBuilderFactory(
            fn (ClaimsFormatter $formatter): Builder => (new Builder(new JoseEncoder, $formatter))
                ->withHeader('kid', $this->keyId())
        );
    }

    /**
     * Duplicated from `OidcController::generateKeyId()`, which is protected. What keeps
     * the two in step is the test comparing an issued header against the live JWKS.
     */
    protected function keyId(): string
    {
        return substr(hash('sha256', (string) file_get_contents(storage_path('oauth-public.key'))), 0, 16);
    }
}
