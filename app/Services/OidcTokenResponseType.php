<?php

declare(strict_types=1);

namespace App\Services;

use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Services\TokenResponseType;

/**
 * The package reads `nonce` off the token request, which never carries one, so the value
 * sent to `/oauth/authorize` was accepted and dropped.
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
