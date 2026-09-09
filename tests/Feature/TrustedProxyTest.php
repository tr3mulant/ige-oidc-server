<?php

use Illuminate\Support\Facades\Route;

/**
 * Host Apache terminates TLS and forwards plain HTTP to the container, so without
 * trusting that hop the framework builds `http://` URLs. Redirect URIs are matched by
 * exact string, which makes that a redirect loop on every login rather than an error.
 */
beforeEach(function () {
    Route::get('/proxy-probe', fn () => [
        'secure' => request()->isSecure(),
        'root' => url('/'),
    ]);
});

test('a request forwarded as https is treated as secure', function () {
    $this->get('/proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJson(['secure' => true]);
});

test('generated URLs use the forwarded scheme and host', function () {
    $this->get('/proxy-probe', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'auth.example.com',
    ])->assertOk()
        ->assertJson(['root' => 'https://auth.example.com']);
});

test('an unforwarded request is not treated as secure', function () {
    $this->get('/proxy-probe')
        ->assertOk()
        ->assertJson(['secure' => false]);
});
