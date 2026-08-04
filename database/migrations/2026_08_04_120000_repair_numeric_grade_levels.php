<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('grades')
            ->select(['id', 'name', 'level'])
            ->orderBy('id')
            ->each(function (object $grade): void {
                $normalizedName = preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $grade->name)));

                if (! preg_match('/^(0|[1-9]|10|11) КЛАСС$/u', $normalizedName, $matches)) {
                    return;
                }

                $level = (int) $matches[1];

                if ((int) $grade->level !== $level || $grade->level === null) {
                    DB::table('grades')->where('id', $grade->id)->update(['level' => $level]);
                }
            });
    }

    public function down(): void
    {
        // Data repair is intentionally non-destructive: prior null/incorrect
        // values cannot be reconstructed safely without changing valid levels.
    }
};
