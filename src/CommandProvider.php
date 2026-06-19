<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers the `composer assets` and `composer assets:check` commands.
 */
final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<\Composer\Command\BaseCommand>
     */
    public function getCommands(): array
    {
        return [new AssetsCommand(), new AssetsCheckCommand()];
    }
}
