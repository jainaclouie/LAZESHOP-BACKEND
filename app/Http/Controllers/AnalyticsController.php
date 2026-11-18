<?php

namespace App\Http\Controllers;

use App\Services\BusinessAnalytics;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __invoke(Request $r, BusinessAnalytics $ba)
    {
        $budget = max(0, (float) $r->query('budget', 50000));

        return $ba->run($budget);
    }
}
