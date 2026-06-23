<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupDatabase extends Command
{

    protected $signature = 'backup:database';

    protected $description = 'Create database backup';

    public function handle()
    {

        $db = config('database.connections.mysql.database');

        $user = config('database.connections.mysql.username');

        $password = config('database.connections.mysql.password');

        $file = storage_path('app/backups/db_' . date('Y_m_d_H_i_s') . '.sql');

        $command = "mysqldump --user={$user} --password={$password} {$db} > {$file}";

        exec($command);

        $this->info('Database backup created: ' . $file);

    }

}