<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin entry point.
 *
 * Hooks `post-install-cmd` / `post-update-cmd` to scaffold asset files, and
 * exposes the `composer assets` command via the Capable provider.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;

    /** Guards against scaffolding twice within a single Composer invocation. */
    private bool $ran = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostCmd',
        ];
    }

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            \Composer\Plugin\Capability\CommandProvider::class => CommandProvider::class,
        ];
    }

    public function onPostCmd(\Composer\Script\Event $event): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        (new Handler($this->composer, $this->io))->run($event->isDevMode());
    }
}
