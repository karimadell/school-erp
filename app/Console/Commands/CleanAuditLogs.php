<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AuditLog;
use Carbon\Carbon;

class CleanAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:clean {--days=180 : Delete logs older than X days}';

    /**
     * The console command description.
     */
    protected $description = 'Clean old audit logs automatically';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $beforeDate = Carbon::now()->subDays($days);

        $count = AuditLog::where('created_at', '<', $beforeDate)->count();

        AuditLog::where('created_at', '<', $beforeDate)->delete();

        $this->info("🧹 Deleted {$count} audit logs older than {$days} days.");

        return self::SUCCESS;
    }
}