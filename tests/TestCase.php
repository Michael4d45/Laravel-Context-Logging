<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextLoggingServiceProvider;
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
            'ContextLog' => \Michael4d45\ContextLogging\Log::class,
        ];
    }
}
