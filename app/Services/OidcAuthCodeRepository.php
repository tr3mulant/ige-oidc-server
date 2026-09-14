<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\RemembersAuthorizationNonce;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * `AuthCodeGrant` revokes the used code before the response type builds its body, so
 * `revokeAuthCode()` is the last point that knows which code this exchange is for. That
 * is what lets `resolveNonce()` answer without decrypting the code payload.
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
     * Consumed, not read: a refresh exchange revokes no code, so a lingering value would
     * land the previous login's nonce on a token that must carry none.
     */
    public function pullRedeemedNonce(): ?string
    {
        $nonce = $this->redeemedNonce;

        $this->redeemedNonce = null;

        return $nonce;
    }
}
