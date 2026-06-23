<?php

namespace App\Providers;

use App\Models\StudentFile;
use App\Policies\StudentFilePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        StudentFile::class => StudentFilePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}