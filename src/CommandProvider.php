<?php

declare(strict_types=1);

namespace Kanopi\Composer\Assets;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers the `composer assets` command.
 */
final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<\Composer\Command\BaseCommand>
     */
    public function getCommands(): array
    {
        return [new AssetsCommand()];
    }
}
