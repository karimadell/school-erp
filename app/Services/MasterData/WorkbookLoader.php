<?php

namespace App\Services\MasterData;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/** Invocation-scoped workbook cache and physical-load instrumentation. */
final class WorkbookLoader
{
    /** @var array<string, Spreadsheet> */
    private array $workbooks = [];

    /** @var array<string, mixed> */
    private array $derived = [];

    /** @var array<string, int> */
    private array $physicalLoads = [];

    private bool $persistenceStarted = false;

    public function load(string $path): Spreadsheet
    {
        $key = $this->key($path);
        if (isset($this->workbooks[$key])) {
            return $this->workbooks[$key];
        }
        if ($this->persistenceStarted) {
            throw new RuntimeException('XLSX physical loading is forbidden inside the reconciliation transaction.');
        }

        $this->physicalLoads[$key] = ($this->physicalLoads[$key] ?? 0) + 1;

        return $this->workbooks[$key] = IOFactory::load($path);
    }

    public function remember(string $namespace, string $path, callable $callback): mixed
    {
        $key = $namespace.':'.$this->key($path);

        return $this->derived[$key] ??= $callback($this->load($path));
    }

    public function physicalLoadCount(): int
    {
        return array_sum($this->physicalLoads);
    }

    public function forbidFurtherPhysicalLoads(): void
    {
        $this->persistenceStarted = true;
    }

    /** @return array<string, int> */
    public function physicalLoads(): array
    {
        return $this->physicalLoads;
    }

    private function key(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("Source workbook not found: {$path}");
        }

        return (realpath($path) ?: $path).':'.hash_file('sha256', $path);
    }
}
