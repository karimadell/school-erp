<?php

namespace App\Support;

final class TransportPermissions
{
    public const VIEW = 'view transport';

    public const MANAGE_ROUTES = 'manage transport routes';

    public const MANAGE_VEHICLES = 'manage transport vehicles';

    public const MANAGE_ASSIGNMENTS = 'manage transport assignments';

    public const VIEW_FINANCE = 'view transport finance';

    public const ALL = [self::VIEW, self::MANAGE_ROUTES, self::MANAGE_VEHICLES, self::MANAGE_ASSIGNMENTS, self::VIEW_FINANCE];
}
