<?php

namespace Michael\ContextLogging\Tests;

use Michael\ContextLogging\ContextLoggingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            ContextLoggingServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'ContextLog' => \Michael\ContextLogging\Log::class,
        ];
    }
}
