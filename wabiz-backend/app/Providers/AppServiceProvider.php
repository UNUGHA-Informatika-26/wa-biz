<?php

namespace App\Providers;

use App\Contracts\AiOrchestrator;
use App\Contracts\AiProviderInterface;
use App\Contracts\WhatsAppWebhookSignatureVerifier;
use App\Models\User;
use App\Services\AI\DeterministicAiOrchestrator;
use App\Services\AI\GeminiAiProvider;
use App\Services\WhatsApp\ConfiguredHmacSignatureVerifier;
use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use App\Services\WhatsApp\WppConnectWhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WhatsAppWebhookSignatureVerifier::class,
            ConfiguredHmacSignatureVerifier::class,
        );
        $this->app->bind(AiOrchestrator::class, DeterministicAiOrchestrator::class);
        $this->app->bind(AiProviderInterface::class, GeminiAiProvider::class);
        $this->app->singleton(WhatsAppProviderRegistry::class, fn ($app): WhatsAppProviderRegistry => new WhatsAppProviderRegistry([
            $app->make(MetaCloudWhatsAppProvider::class),
            $app->make(WppConnectWhatsAppProvider::class),
        ]));
        $this->app->bind(WhatsAppMessageSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $identity = mb_strtolower(trim((string) $request->input('email')));
            $key = hash('sha256', $identity.'|'.(string) $request->ip());

            return Limit::perMinute(10)->by('login:'.$key);
        });
        Gate::define('manage-organization', fn (User $user): bool => $user->role === 'owner');
        Gate::define('manage-organization-operations', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('access-organization-operations', fn (User $user): bool => in_array($user->role, ['owner', 'admin', 'staff'], true));
    }
}
