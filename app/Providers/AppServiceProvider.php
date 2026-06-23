<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Models
use App\Models\User;
use App\Models\Student;
use App\Models\CashAccount;
use App\Models\CashTransaction;

// Observer
use App\Observers\AuditObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        User::observe(AuditObserver::class);
        Student::observe(AuditObserver::class);

        // لو الموديلات موجودة
        if (class_exists(CashAccount::class)) {
            CashAccount::observe(AuditObserver::class);
        }

        if (class_exists(CashTransaction::class)) {
            CashTransaction::observe(AuditObserver::class);
        }
    }
}