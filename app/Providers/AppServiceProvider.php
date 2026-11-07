<?php

namespace App\Providers;

use App\Modules\Delivery\Listeners\QueueShipmentOnReady;
use App\Modules\Notifications\Listeners\SendCustomerOrderPush;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Policies\CashierShiftPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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
        $this->configureRateLimiting();

        Gate::policy(CashierShift::class, CashierShiftPolicy::class);

        // Order delivery paid + ready → antrekan shipment Biteship.
        Event::listen(OrderStatusChanged::class, QueueShipmentOnReady::class);

        // Status penting → push FCM ke device customer (jika terdaftar).
        Event::listen(OrderStatusChanged::class, SendCustomerOrderPush::class);
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('orders', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('payments', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('shifts', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('purchases', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('customer-public', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->ip()));

        RateLimiter::for('customer-auth', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));

        RateLimiter::for('customer-password-reset', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip()));

        RateLimiter::for('customer-me', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->attributes->get('customer')->id ?? $request->ip()));

        RateLimiter::for('customer-checkout', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Factory::guessFactoryNamesUsing(self::guessFactoryName(...));

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

    /**
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    private static function guessFactoryName(string $modelName): string
    {
        $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

        if (! is_subclass_of($factory, Factory::class)) {
            throw new RuntimeException("Factory [{$factory}] tidak ditemukan.");
        }

        return $factory;
    }
}
