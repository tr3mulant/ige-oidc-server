<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * There is no home page here, and `/` is a redirect rather than a view (plan §1.11).
 *
 * The normal flow never reaches this route at all: a client redirects to
 * `/oauth/authorize`, the `auth` middleware records `url.intended`, and Fortify's
 * `LoginResponse` returns the person to it. The destination travels with the request, so
 * nothing has to be decided. This route handles only the arrival that carries no
 * destination — someone typing the hostname — and the three reasons to do that are all
 * self-service credential maintenance, which is where it sends them.
 *
 * It deliberately reads no input. A `?redirect=` parameter on the root of an
 * authentication host is an open redirect: a genuine login page on the genuine domain
 * that hands the visitor onward to whoever asked. Users are routed by OIDC's
 * `redirect_uri`, which Passport matches against the registered client by exact string,
 * and by nothing else.
 *
 * It also does not fall back to a client application. Defaulting the bare visit to
 * `tools.*` would make the identity provider know a client's hostname and prefer one
 * among them, and adding the third application would then mean editing this repository.
 */
class RootRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user() === null
            ? redirect()->route('login')
            : redirect()->route('account.security');
    }
}
