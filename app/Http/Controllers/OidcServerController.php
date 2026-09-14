<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Admin9\OidcServer\Http\Controllers\OidcController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Throwable;

/**
 * Corrects the package's post-logout redirect validation, which checks the wrong list
 * with the wrong comparison.
 *
 * It validates against the client's `redirect_uris` — where a browser returns with an
 * authorization code — rather than its registered post-logout URIs, and matches by path
 * prefix. So an app asking to land a signed-out user on its own home page is refused,
 * while a stray path under its callback is accepted. Both silently: a rejected URI
 * produces a redirect to this host, not an error, so a client cannot tell the two apart.
 */
class OidcServerController extends OidcController
{
    /**
     * The package advertises whatever `post_logout_redirect_uris_supported` holds, which
     * governs nothing. Replacing it with the registered union keeps the document honest
     * without copying the other twenty keys.
     */
    public function discovery(): JsonResponse
    {
        $response = parent::discovery();

        /** @var array<string, mixed> $document */
        $document = $response->getData(true);
        $document['post_logout_redirect_uris_supported'] = $this->registeredPostLogoutRedirectUris();

        return $response->setData($document);
    }

    /**
     * Ignores the list the parent hands over. RP-Initiated Logout §2 requires an exact
     * match against the URIs registered *by the requesting client*, and requires no
     * redirect at all when that client cannot be identified — so an absent or unusable
     * `id_token_hint` fails closed rather than falling back to this host's own URL.
     *
     * @param  list<string>  $allowedUris  the parent's `redirect_uris`, deliberately unused
     */
    protected function isValidPostLogoutUri(string $uri, array $allowedUris): bool
    {
        $client = $this->clientFromIdTokenHint(request());

        if ($client === null) {
            return false;
        }

        return in_array($uri, $client->post_logout_redirect_uris ?? [], true);
    }

    /**
     * Re-read rather than passed down: the parent resolves the same client in `logout()`
     * and discards it before validating.
     */
    protected function clientFromIdTokenHint(Request $request): ?Client
    {
        $hint = $request->query('id_token_hint');

        if (! is_string($hint) || $hint === '') {
            return null;
        }

        try {
            /** @var Plain $token */
            $token = (new Parser(new JoseEncoder))->parse($hint);
            $audience = $token->claims()->get('aud');
        } catch (Throwable) {
            return null;
        }

        $clientId = is_array($audience) ? ($audience[0] ?? null) : $audience;

        return is_string($clientId) ? Passport::client()->newQuery()->find($clientId) : null;
    }

    /**
     * @return list<string>
     */
    protected function registeredPostLogoutRedirectUris(): array
    {
        $uris = Passport::client()->newQuery()
            ->where('revoked', false)
            ->pluck('post_logout_redirect_uris')
            ->flatten()
            ->filter(fn ($uri): bool => is_string($uri) && $uri !== '')
            ->unique()
            ->sort()
            ->values();

        return $uris->all();
    }
}
