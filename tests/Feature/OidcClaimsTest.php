<?php

use Admin9\OidcServer\Contracts\OidcUserInterface;
use App\Models\User;

test('the user model satisfies the contract the package checks for', function () {
    expect(User::factory()->make())->toBeInstanceOf(OidcUserInterface::class);
})->note('OidcController guards claim resolution with an instanceof check, so failing this returns an empty 200 rather than an error.');

test('the profile scope issues preferred_username', function () {
    $user = User::factory()->create(['username' => 'robinvance']);

    expect($user->getOidcClaims(['openid', 'profile']))
        ->toHaveKey('preferred_username', 'robinvance');
});

test('preferred_username is withheld from a client not scoped for it', function () {
    $user = User::factory()->create(['username' => 'robinvance']);

    expect($user->getOidcClaims(['openid']))->not->toHaveKey('preferred_username');
});

test('the profile scope is configured to carry preferred_username', function () {
    expect(config('oidc-server.scopes.profile.claims'))->toContain('preferred_username');
})->note('The claim is only resolved if listed here. Dropping it yields an empty REMOTE_USER on the legacy intranet, which is an authorization input.');

test('username cannot be set by mass assignment', function () {
    $user = new User(['name' => 'Impostor', 'email' => 'impostor@example.com', 'username' => 'robinvance']);

    expect($user->username)->toBeNull();
});
