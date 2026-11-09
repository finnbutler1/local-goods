<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** In production, errors also reach the system log, outside the app folder. */
class LoggingChannelConfigTest extends TestCase
{
    /**
     * config/logging.php as it reads with these environment values.
     *
     * @return array<string, mixed>
     */
    private function configWith(string $appEnv, string $logChannel): array
    {
        $names = ['APP_ENV' => $appEnv, 'LOG_CHANNEL' => $logChannel];
        $before = [];
        foreach ($names as $name => $value) {
            $before[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("$name=$value");
        }

        try {
            return require config_path('logging.php');
        } finally {
            foreach ($before as $name => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                putenv($put === false ? $name : "$name=$put");
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function liveChannels(): array
    {
        return ['stderr, the live value' => ['stderr'], 'stack' => ['stack'], 'single' => ['single']];
    }

    #[DataProvider('liveChannels')]
    public function test_production_wraps_the_current_channel(string $channel): void
    {
        $config = $this->configWith('production', $channel);

        $this->assertSame('with_system_log', $config['default']);
        $this->assertSame(['system_errors', $channel], $config['channels']['with_system_log']['channels']);
        $this->assertArrayHasKey($channel, $config['channels'], 'The wrapped channel must exist.');
    }

    public function test_outside_production_the_default_is_unchanged(): void
    {
        $this->assertSame('stack', $this->configWith('local', 'stack')['default']);
    }

    public function test_the_channels_build_with_errors_only_going_to_the_system_log(): void
    {
        config(['logging.channels' => $this->configWith('production', 'stderr')['channels']]);

        $group = Log::channel('with_system_log')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(WhatFailureGroupHandler::class, $group, 'A failing log must not stop the other one.');
        $handlers = (new \ReflectionProperty($group, 'handlers'))->getValue($group);

        $this->assertInstanceOf(ErrorLogHandler::class, $handlers[0]);
        $this->assertSame(Level::Error, $handlers[0]->getLevel());
        $this->assertInstanceOf(StreamHandler::class, $handlers[1]);
        $this->assertSame('php://stderr', $handlers[1]->getUrl());
    }
}
