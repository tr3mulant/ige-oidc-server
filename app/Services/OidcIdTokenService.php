<?php

declare(strict_types=1);

namespace App\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Services\IdTokenService;
use DateTimeImmutable;
use Lcobucci\JWT\ClaimsFormatter;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Two corrections to the package's ID token.
 *
 * It names no key, though `jwks.json` publishes a `kid`, so a client cannot tell which
 * published key to verify against and a rotation can never overlap two.
 *
 * And it expires with the access token, which leaves `tokens.id_token_ttl` — a lifetime
 * the plan argued for and a test guards — read by nothing.
 */
class OidcIdTokenService extends IdTokenService
{
    /**
     * Cloned so the shorter expiry reaches the ID token alone; the access token is still
     * the caller's and still has to outlive it.
     */
    public function generateToken(
        AccessTokenEntityInterface $accessToken,
        OidcUserInterface $user,
        ClientEntityInterface $client,
        ?string $nonce = null
    ): string {
        $idTokenExpiry = clone $accessToken;
        $idTokenExpiry->setExpiryDateTime(
            new DateTimeImmutable('+'.config('oidc-server.tokens.id_token_ttl').' seconds')
        );

        return parent::generateToken($idTokenExpiry, $user, $client, $nonce);
    }

    /**
     * The formatter the package asks for is discarded: its microsecond conversion emits
     * `iat` and `exp` as floats, and as integers on the one-in-a-million token built on a
     * whole second — a type decided by the clock rather than by anything here. Whole
     * seconds also match `auth_time`, which never passes through a formatter at all.
     */
    protected function getJwtConfig(): Configuration
    {
        return parent::getJwtConfig()->withBuilderFactory(
            fn (ClaimsFormatter $formatter): Builder => (new Builder(new JoseEncoder, ChainedFormatter::withUnixTimestampDates()))
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
