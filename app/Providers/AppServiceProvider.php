<?php

namespace App\Providers;

use Admin9\OidcServer\Services\IdTokenService;
use App\Services\OidcIdTokenService;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * Extended rather than re-bound: the package binds this abstract too, and
         * provider registration order is not guaranteed. Extenders survive a later
         * `bind()`; a competing binding would not.
         */
        $this->app->extend(
            IdTokenService::class,
            fn ($service, $app) => $app->make(OidcIdTokenService::class),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * The device authorization grant is for inputs a browser cannot reach — TVs,
         * CLIs. Every client here is a web application, so it has no use for one.
         *
         * It is already inert: `PassportServiceProvider.php:171` registers the grant
         * only when the `passport.device` route exists, and it does not, because
         * `admin9/laravel-oidc-server` calls `Passport::ignoreRoutes()` and registers
         * its own set without it. Turning the flag off states that intent and closes a
         * sharper edge — `passport:client` otherwise offers to enable the device flow
         * on any client it creates (`ClientCommand.php:153-154`), and an operator
         * running it interactively could say yes to a grant nobody has reviewed.
         */
        Passport::$deviceCodeGrantEnabled = false;
    }
}
