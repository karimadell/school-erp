<?php

namespace App\Services\MasterData;

final class ReconciliationPerformance
{
    private float $startedAt;

    private string $stage = 'command_started';

    private array $timings = [];

    public function __construct()
    {
        $this->startedAt = hrtime(true);
    }

    public function measure(string $stage, callable $callback): mixed
    {
        $this->stage = $stage;
        $started = hrtime(true);
        try {
            return $callback();
        } finally {
            $this->timings[$stage] = round((hrtime(true) - $started) / 1e9, 6);
        }
    }

    public function mark(string $stage): void
    {
        $this->stage = $stage;
    }

    public function report(): array
    {
        return ['stage_reached' => $this->stage, 'elapsed_seconds' => round((hrtime(true) - $this->startedAt) / 1e9, 6), 'stage_seconds' => $this->timings];
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function timings(): array
    {
        return $this->timings;
    }
}
