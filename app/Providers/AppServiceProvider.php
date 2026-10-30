<?php

namespace App\Providers;

use App\Authorization\PermissionRegistry;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\Tickets\NotificationCenterService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        RateLimiter::for('ticket-create', fn (Request $request): Limit => Limit::perMinute(10)->by($this->rateLimitKey($request, 'ticket-create')));
        RateLimiter::for('ticket-replies', fn (Request $request): Limit => Limit::perMinute(30)->by($this->rateLimitKey($request, 'ticket-replies')));
        RateLimiter::for('ticket-imports', fn (Request $request): Limit => Limit::perHour(3)->by($this->rateLimitKey($request, 'ticket-imports')));
        RateLimiter::for('ticket-downloads', fn (Request $request): Limit => Limit::perMinute(60)->by($this->rateLimitKey($request, 'ticket-downloads')));
        config([
            'tyro-login.redirects.after_login' => '/dashboard',
            'tyro-login.registration.require_email_verification' => config('ticketing-auth.require_email_verification'),
            'tyro-login.tyro.assign_default_role' => false,
            'tyro-login.two_factor.enabled' => config('ticketing-auth.two_factor_enabled'),
            'tyro-login.two_factor.forced_roles' => config('ticketing-auth.two_factor_forced_roles'),
            'tyro-login.two_factor.skip_roles' => config('ticketing-auth.two_factor_skip_roles'),
        ]);

        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User || ! app(PermissionRegistry::class)->recognizes($ability)) {
                return null;
            }

            return app(EffectivePermissionService::class)->allows($user, $ability);
        });

        View::composer('tyro-dashboard::partials.topbar', function (ViewInstance $view): void {
            $actor = auth()->user();
            $count = $actor instanceof User
                ? app(NotificationCenterService::class)->unreadCount($actor)
                : 0;

            $view->with('ticketUnreadNotificationCount', $count);
        });

        View::composer('customer.*', function (ViewInstance $view): void {
            $actor = auth()->user();
            $count = $actor instanceof User
                ? app(NotificationCenterService::class)->unreadCount($actor)
                : 0;

            $view->with('customerUnreadCount', $count);
        });
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        $actorId = $request->user()?->getAuthIdentifier();

        return $scope.':'.($actorId === null ? 'ip:'.$request->ip() : 'user:'.(string) $actorId);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
