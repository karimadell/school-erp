<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

class ReportController extends Controller
{
    public function restaurant()
    {
        return view('dashboard.reports.restaurant');
    }

    public function restaurantWeekly()
    {
        return view('dashboard.reports.restaurant_weekly');
    }

    public function restaurantKitchen()
    {
        return view('dashboard.reports.restaurant_kitchen');
    }

    public function restaurantKitchenPdf()
    {
        return 'PDF coming soon';
    }
}