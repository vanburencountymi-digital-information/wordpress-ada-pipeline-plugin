<?php

namespace AdaRemediationClient;

/**
 * Gates all plugin behavior on the three wp-config.php constants being present.
 * Later tickets hang their hook registration off boot()'s configured branch.
 */
class Plugin
{
    public const REQUIRED_CONSTANTS = [
        'ADA_REMEDIATION_API_BASE_URL',
        'ADA_REMEDIATION_API_TOKEN',
        'ADA_REMEDIATION_WEBHOOK_SECRET',
    ];

    public static function is_configured(): bool
    {
        foreach (self::REQUIRED_CONSTANTS as $constant) {
            if (!defined($constant)) {
                return false;
            }
        }

        return true;
    }

    public static function boot(): void
    {
        if (!self::is_configured()) {
            return;
        }

        // Hook registration lands here as later tickets (DIC-1899+) add behavior.
    }
}
