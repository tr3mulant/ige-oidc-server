<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Admin9\OidcServer\Http\Controllers\OidcController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The package validates `post_logout_redirect_uri` against the client's `redirect_uris`,
 * by path prefix. RP-Initiated Logout §2 requires the client's registered post-logout
 * URIs, matched exactly.
 */
class OidcServerController extends OidcController
{
    /**
     * Targets the login route directly, not `/`: flash data survives one request, and
     * `RootRedirectController` would spend it on a redirect that renders nothing. A logout
     * leaves a guest, so that controller has only the one branch anyway.
     */
    public function logout(Request $request): Response
    {
        $response = parent::logout($request);

        if ($response instanceof RedirectResponse && $response->getTargetUrl() === url('/')) {
            return $response->setTargetUrl(route('login'))
                ->with('status', __('You have been signed out.'));
        }

        return $response;
    }

    /** Editing the parent's output keeps the advertised list honest without copying it. */
    public function discovery(): JsonResponse
    {
        $response = parent::discovery();

        /** @var array<string, mixed> $document */
        $document = $response->getData(true);
        $document['post_logout_redirect_uris_supported'] = $this->registeredPostLogoutRedirectUris();

        return $response->setData($document);
    }

    /**
     * No identifiable client means no redirect at all — §2 forbids one the OP cannot
     * confirm.
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

    /** Re-read because the parent resolves the same client and discards it before validating. */
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
