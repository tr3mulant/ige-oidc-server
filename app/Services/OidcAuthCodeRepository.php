<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\RemembersAuthorizationNonce;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Persists the authorization nonce and reads it back when the code is redeemed.
 *
 * `AuthCodeGrant::respondToAccessTokenRequest()` revokes the used code before the
 * response type builds its body, so `revokeAuthCode()` is the last point that still knows
 * which code this exchange is for. Holding the value from there is what lets
 * `OidcTokenResponseType::resolveNonce()` answer without decrypting the code payload.
 */
class OidcAuthCodeRepository extends AuthCodeRepository
{
    protected ?string $redeemedNonce = null;

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);

        $nonce = session()->get(RemembersAuthorizationNonce::SESSION_KEY);

        if ($nonce === null) {
            return;
        }

        Passport::authCode()->newQuery()
            ->whereKey($authCodeEntity->getIdentifier())
            ->update(['nonce' => $nonce]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->redeemedNonce = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->value('nonce');

        parent::revokeAuthCode($codeId);
    }

    /**
     * Consumed rather than read: a nonce belongs to the one exchange that redeemed its
     * code. A refresh exchange revokes no code, so leaving the value in place would put
     * the previous login's nonce on a token OIDC Core says must carry none.
     */
    public function pullRedeemedNonce(): ?string
    {
        $nonce = $this->redeemedNonce;

        $this->redeemedNonce = null;

        return $nonce;
    }
}
