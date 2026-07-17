<?php

namespace Michael4d45\ContextLogging\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Michael4d45\ContextLogging\Guzzle\PatchInstaller;

/**
 * Keeps the Guzzle Client patch applied across composer install/update/dump-autoload.
 */
class GuzzlePatchPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        //
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        //
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        //
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Generate sources + wire root autoload before Composer writes classmaps.
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['onPreAutoloadDump', 0],
            // Re-assert classmap entries after Composer regenerates autoload files.
            ScriptEvents::POST_AUTOLOAD_DUMP => ['onPostAutoloadDump', 0],
        ];
    }

    public function onPreAutoloadDump(Event $event): void
    {
        (new PatchInstaller($event->getComposer(), $event->getIO()))->install(preAutoloadDump: true);
    }

    public function onPostAutoloadDump(Event $event): void
    {
        (new PatchInstaller($event->getComposer(), $event->getIO()))->install(preAutoloadDump: false);
    }
}
