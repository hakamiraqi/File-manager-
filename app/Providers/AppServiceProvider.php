<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\LinkedIn\Provider as LinkedInProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        Vite::prefetch(concurrency: 3);

        Relation::morphMap([
            'exam' => \App\Models\Exams\Exam::class,
        ]);

        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event) {
            $event->extendSocialite('linkedin-openid', LinkedInProvider::class);
        });

        $this->configureOauthFromSettings();
        $this->configureMailFromSettings();

        foreach ([
            'manage-appearance' => 'can_manage_appearance',
            'manage-users' => 'can_manage_users',
            'manage-translations' => 'can_manage_translations',
            'view-statistics' => 'can_view_statistics',
            'manage-settings' => 'can_manage_settings',
        ] as $ability => $capability) {
            Gate::define($ability, fn ($user) => $user->hasAdminCapability($capability));
        }

        Gate::define('manage-exams', fn ($user) => $user->canManageExams());

        Gate::define('access-settings-page', fn ($user) => $user->hasAdminCapability('can_manage_settings')
            || $user->hasAdminCapability('can_manage_appearance'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }

    /**
     * OAuth client credentials are entered by the admin from the settings
     * UI (not .env), so they are pulled from the database into Laravel's
     * runtime `services.*` config on every boot.
     */
    private function configureOauthFromSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = Setting::all_settings();
        $base = rtrim(config('app.url'), '/');

        config([
            'services.google' => [
                'client_id' => $settings['oauth_google_client_id'] ?? '',
                'client_secret' => $settings['oauth_google_client_secret'] ?? '',
                'redirect' => "{$base}/auth/google/callback",
            ],
            'services.github' => [
                'client_id' => $settings['oauth_github_client_id'] ?? '',
                'client_secret' => $settings['oauth_github_client_secret'] ?? '',
                'redirect' => "{$base}/auth/github/callback",
            ],
            'services.linkedin-openid' => [
                'client_id' => $settings['oauth_linkedin_client_id'] ?? '',
                'client_secret' => $settings['oauth_linkedin_client_secret'] ?? '',
                'redirect' => "{$base}/auth/linkedin/callback",
            ],
        ]);
    }

    /**
     * SMTP settings are configurable from the admin "Mail" page instead of
     * only via .env, so they override the mailers.smtp config at runtime
     * whenever they have been set.
     */
    private function configureMailFromSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = Setting::all_settings();

        if (empty($settings['mail_host'])) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings['mail_host'],
            'mail.mailers.smtp.port' => (int) ($settings['mail_port'] ?? 587),
            'mail.mailers.smtp.username' => $settings['mail_username'] ?? null,
            'mail.mailers.smtp.password' => $settings['mail_password'] ?? null,
            'mail.mailers.smtp.encryption' => $settings['mail_encryption'] ?: null,
            'mail.from.address' => $settings['mail_from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $settings['mail_from_name'] ?? config('mail.from.name'),
        ]);
    }
}
