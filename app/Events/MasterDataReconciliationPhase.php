<?php

namespace App\Events;

final readonly class MasterDataReconciliationPhase
{
    public function __construct(public string $phase) {}
}
