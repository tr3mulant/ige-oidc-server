<?php

declare(strict_types=1);

namespace App\Services;

use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Services\TokenResponseType;

/**
 * The package reads `nonce` off the current request, but that is the back-channel token
 * request, which never carries one — so a nonce sent to `/oauth/authorize` was accepted
 * and dropped, and any client enforcing the round-trip rejected every login.
 */
class OidcTokenResponseType extends TokenResponseType
{
    public function __construct(
        IdTokenService $idTokenService,
        protected OidcAuthCodeRepository $authCodes,
    ) {
        parent::__construct($idTokenService);
    }

    protected function resolveNonce(): ?string
    {
        return $this->authCodes->pullRedeemedNonce();
    }
}
