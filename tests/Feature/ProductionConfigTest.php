<?php

namespace Tests\Feature;

use App\Support\ProductionConfig;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * app:check-production-config stops a deploy whose settings are unsafe
 * (the deploy hook runs it before migrating), and production never shows a
 * debug page even if APP_DEBUG slipped through.
 */
class ProductionConfigTest extends TestCase
{
    private const SECRET = 'test-origin-secret-not-real';

    private function safeProduction(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'session.secure' => true,
            'services.cloudfront.enforce' => true,
            'services.cloudfront.origin_secret' => self::SECRET,
        ]);
    }

    public function test_safe_production_settings_pass(): void
    {
        $this->safeProduction();

        $this->artisan('app:check-production-config')->expectsOutput('Production settings are safe.')->assertSuccessful();
    }

    public static function unsafe(): array
    {
        return [
            'debug on' => [['app.debug' => true], 'APP_DEBUG must be "false"'],
            'not production' => [['app.env' => 'local'], 'APP_ENV must be "production"'],
            'cookie not secure' => [['session.secure' => null], 'SESSION_SECURE_COOKIE must be "true"'],
            'cookie secure false' => [['session.secure' => false], 'SESSION_SECURE_COOKIE must be "true"'],
            'no CloudFront secret while enforcing' => [['services.cloudfront.origin_secret' => ''], 'CLOUDFRONT_ORIGIN_SECRET is empty'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_an_unsafe_setting_fails_the_check(array $override, string $message): void
    {
        $this->safeProduction();
        config($override);

        $this->assertNotEmpty(ProductionConfig::problems());
        $this->artisan('app:check-production-config')->expectsOutputToContain($message)->assertFailed();
    }

    public function test_the_kill_switch_allows_an_empty_secret(): void
    {
        $this->safeProduction();
        config(['services.cloudfront.enforce' => false, 'services.cloudfront.origin_secret' => null]);

        $this->artisan('app:check-production-config')->assertSuccessful();
    }

    public function test_the_check_never_prints_the_secret(): void
    {
        $this->safeProduction();
        config(['app.debug' => true]);

        $this->artisan('app:check-production-config')->doesntExpectOutputToContain(self::SECRET)->assertFailed();
    }

    public function test_production_turns_debug_off_and_logs_it(): void
    {
        Log::spy();
        config(['app.env' => 'production', 'app.debug' => true]);

        ProductionConfig::forceDebugOff();

        $this->assertFalse(config('app.debug'));
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_debug_is_left_alone_outside_production(): void
    {
        config(['app.env' => 'local', 'app.debug' => true]);

        ProductionConfig::forceDebugOff();

        $this->assertTrue(config('app.debug'));
    }

    public function test_beanstalk_config_keeps_the_safe_values(): void
    {
        $config = file_get_contents(base_path('.ebextensions/laravel.config'));

        $this->assertMatchesRegularExpression('/^\s+APP_ENV: production$/m', $config);
        $this->assertMatchesRegularExpression('/^\s+APP_DEBUG: "false"$/m', $config);
        $this->assertMatchesRegularExpression('/^\s+SESSION_SECURE_COOKIE: "true"$/m', $config);
        $this->assertStringNotContainsString('ORIGIN_VERIFY_ENFORCE: "false"', $config);
    }
}
