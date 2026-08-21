<?php

namespace App\Support;

final readonly class TimetableGenerationTelemetry
{
    public function __construct(
        public int $nodes,
        public int $backtracks,
        public float $elapsedSeconds,
        public int $queryCount,
        public float $queryTimeMs,
        public bool $succeeded,
    ) {}
}
