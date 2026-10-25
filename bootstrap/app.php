<?php

use App\Http\Middleware\ConvertEmptyStringsToNull;
use App\Http\Middleware\DetectLanguagePrefer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware as MiddlewareConfiguration;

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Laravel 11+ builds the application through the configuration builder
| instead of the legacy `app/Http/Kernel.php` and `app/Console/Kernel.php`
| classes. The middleware stack, middleware groups and aliases below are a
| one-to-one port of those legacy classes, so the runtime behaviour of the
| application (and of plugins relying on it) is unchanged.
|
*/

$app = Application::configure($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__))
    /*
     * Event discovery is disabled on purpose: this application already
     * registers its listeners explicitly through `App\Providers\EventServiceProvider`
     * and `App\Providers\PluginServiceProvider`. Leaving framework level
     * discovery enabled would register every class in `app/Listeners` twice.
     */
    ->withEvents(false)
    /*
     * `passport:keys` must also be available outside of the console (see
     * `SetupController::finish()`), therefore it is registered explicitly.
     */
    ->withCommands([
        \Laravel\Passport\Console\KeysCommand::class,
        \App\Console\Commands\BsInstallCommand::class,
        \App\Console\Commands\OptionsCacheCommand::class,
        \App\Console\Commands\PluginDisableCommand::class,
        \App\Console\Commands\PluginEnableCommand::class,
        \App\Console\Commands\SaltRandomCommand::class,
        \App\Console\Commands\UpdateCommand::class,
    ])
    ->withMiddleware(function (MiddlewareConfiguration $middleware) {
        /*
         * Replicates the global middleware stack of the former
         * `App\Http\Kernel::$middleware` (Laravel 10) verbatim.
         */
        $middleware->use([
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            DetectLanguagePrefer::class,
        ]);

        $middleware->group('web', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnforceEverGreen::class,
            \App\Http\Middleware\RedirectToSetup::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->group('api', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->group('authorize', [
            'auth:web',
            \App\Http\Middleware\RejectBannedUser::class,
            \App\Http\Middleware\EnsureEmailFilled::class,
            \App\Http\Middleware\FireUserAuthenticated::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'setup' => \App\Http\Middleware\CheckInstallation::class,
            'verified' => \App\Http\Middleware\CheckUserVerified::class,
            'scope' => \Laravel\Passport\Http\Middleware\CheckTokenForAnyScope::class,
            'scopes' => \Laravel\Passport\Http\Middleware\CheckToken::class,
        ]);
    })
    ->create();

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| The application ships its own exception handler (it filters stack traces
| and renders Passport scope errors as JSON), so it is bound explicitly
| instead of relying on the framework default.
|
*/

$app->singleton(
    \Illuminate\Contracts\Debug\ExceptionHandler::class,
    \App\Exceptions\Handler::class
);

return $app;
