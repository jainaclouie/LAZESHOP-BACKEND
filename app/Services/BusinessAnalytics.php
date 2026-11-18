<?php

namespace App\Services;

use App\Models\{Campaign, Customer, Product, Sale};
use Carbon\Carbon;

/**
 * Business Analytics pipeline: Descriptive -> Predictive -> Prescriptive.
 * Each stage consumes the output of the previous one.
 */
class BusinessAnalytics
{
    public function run(float $budget = 50000): array
    {
        $sales = Sale::with(['product', 'customer', 'campaign'])->get();
        $campaigns = Campaign::all();

        $descriptive = $this->descriptive($sales, $campaigns);
        $predictive = $this->predictive($descriptive, $sales);
        $prescriptive = $this->prescriptive($descriptive, $predictive, $sales, $budget);

        return compact('descriptive', 'predictive', 'prescriptive');
    }

    // ---------- STEP 1: DESCRIPTIVE (what happened?) ----------
    private function descriptive($sales, $campaigns): array
    {
        $revenue = (float) $sales->sum('total');
        $profit = (float) $sales->sum(fn ($s) => $s->total - $s->product->cost * $s->quantity);
        $orders = $sales->count();

        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->startOfMonth()->subMonths($i);
            $key = $m->format('Y-m');
            $inMonth = $sales->filter(fn ($s) => $s->sold_at->format('Y-m') === $key);
            $monthly[] = [
                'month' => $m->format('M Y'),
                'revenue' => round((float) $inMonth->sum('total'), 2),
                'orders' => $inMonth->count(),
                'spend' => round((float) $campaigns->filter(fn ($c) => $c->start_date->format('Y-m') === $key)->sum('spend'), 2),
                'partial' => $i === 0,
            ];
        }

        $group = fn ($items, $fn) => $items->groupBy($fn);

        $byCategory = $group($sales, fn ($s) => $s->product->category)->map(fn ($g, $k) => [
            'name' => $k,
            'revenue' => round((float) $g->sum('total'), 2),
            'units' => (int) $g->sum('quantity'),
            'profit' => round((float) $g->sum(fn ($s) => $s->total - $s->product->cost * $s->quantity), 2),
        ])->sortByDesc('revenue')->values();

        $byChannel = $group($sales, 'sales_channel')->map(fn ($g, $k) => [
            'name' => $k, 'revenue' => round((float) $g->sum('total'), 2), 'orders' => $g->count(),
        ])->values();

        $byCity = $group($sales, fn ($s) => $s->customer->city)->map(fn ($g, $k) => [
            'name' => $k, 'revenue' => round((float) $g->sum('total'), 2),
        ])->sortByDesc('revenue')->values();

        $ageBand = fn ($a) => $a <= 24 ? '18-24' : ($a <= 34 ? '25-34' : '35+');
        $byAge = $group($sales, fn ($s) => $ageBand($s->customer->age))->map(fn ($g, $k) => [
            'name' => $k, 'revenue' => round((float) $g->sum('total'), 2),
        ])->sortKeys()->values();

        $topProducts = $group($sales, 'product_id')->map(fn ($g) => [
            'name' => $g->first()->product->name,
            'revenue' => round((float) $g->sum('total'), 2),
            'units' => (int) $g->sum('quantity'),
        ])->sortByDesc('revenue')->take(8)->values();

        $channelRoas = $campaigns->groupBy('channel')->map(function ($cs, $ch) use ($sales) {
            $spend = (float) $cs->sum('spend');
            $rev = (float) $sales->filter(fn ($s) => $s->campaign && $s->campaign->channel === $ch)->sum('total');

            return [
                'channel' => $ch,
                'campaigns' => $cs->count(),
                'spend' => round($spend, 2),
                'revenue' => round($rev, 2),
                'roas' => $spend > 0 ? round($rev / $spend, 2) : 0,
            ];
        })->sortByDesc('roas')->values();

        return [
            'kpis' => [
                'revenue' => round($revenue, 2),
                'profit' => round($profit, 2),
                'margin_pct' => $revenue ? round($profit / $revenue * 100, 1) : 0,
                'orders' => $orders,
                'avg_order_value' => $orders ? round($revenue / $orders, 2) : 0,
                'customers' => Customer::count(),
                'products' => Product::count(),
                'ad_spend' => round((float) $campaigns->sum('spend'), 2),
                'low_stock' => Product::whereColumn('stock', '<=', 'reorder_level')->count(),
            ],
            'monthly' => $monthly,
            'by_category' => $byCategory,
            'by_sales_channel' => $byChannel,
            'by_city' => $byCity,
            'by_age' => $byAge,
            'top_products' => $topProducts,
            'channel_roas' => $channelRoas,
        ];
    }

    // ---------- STEP 2: PREDICTIVE (what will happen?) ----------
    private function predictive(array $d, $sales): array
    {
        // Train on full months only (drop the partial current month).
        $full = array_slice($d['monthly'], 0, 11);
        $y = array_column($full, 'revenue');
        $x = range(0, count($y) - 1);
        [$slope, $intercept, $r2] = $this->linreg($x, $y);

        $forecast = [];
        for ($i = 0; $i < 3; $i++) {
            $idx = count($y) + $i;
            $forecast[] = [
                'month' => Carbon::now()->startOfMonth()->addMonths($i)->format('M Y'),
                'predicted_revenue' => round(max(0, $intercept + $slope * $idx), 2),
            ];
        }

        $spend = array_column($full, 'spend');
        $corr = $this->correlation($spend, $y);

        $recentAvg = max(1, array_sum(array_slice($y, -3)) / 3);
        $growth = max(0.8, min(1.5, $forecast[0]['predicted_revenue'] / $recentAvg));

        // Demand per product: avg monthly units over the last 90 days x growth factor.
        $since = Carbon::now()->subDays(90);
        $demand = $sales->filter(fn ($s) => $s->sold_at->gte($since))
            ->groupBy('product_id')->map(fn ($g) => round($g->sum('quantity') / 3 * $growth, 2));

        return [
            'model' => 'Simple linear regression on monthly revenue',
            'slope_per_month' => round($slope, 2),
            'intercept' => round($intercept, 2),
            'r_squared' => round($r2, 3),
            'trend' => $slope >= 0 ? 'upward' : 'downward',
            'forecast' => $forecast,
            'spend_revenue_correlation' => round($corr, 3),
            'growth_factor' => round($growth, 3),
            'demand' => $demand,
        ];
    }

    // ---------- STEP 3: PRESCRIPTIVE (what should we do?) ----------
    private function prescriptive(array $d, array $p, $sales, float $budget): array
    {
        $restock = Product::with('supplier')->get()->map(function ($pr) use ($p) {
            $monthly = (float) ($p['demand'][$pr->id] ?? 0);
            $lead = $pr->supplier->lead_time_days ?? 7;
            $target = (int) ceil($monthly * (1 + $lead / 30));
            $qty = max(0, $target - $pr->stock, $pr->stock <= $pr->reorder_level ? $pr->reorder_level * 2 - $pr->stock : 0);

            return [
                'id' => $pr->id,
                'name' => $pr->name,
                'stock' => $pr->stock,
                'reorder_level' => $pr->reorder_level,
                'forecast_monthly_units' => $monthly,
                'lead_time_days' => $lead,
                'order_qty' => $qty,
                'order_cost' => round($qty * $pr->cost, 2),
                'priority' => $pr->stock <= $pr->reorder_level ? 'High' : ($qty > 0 ? 'Medium' : 'OK'),
            ];
        })->filter(fn ($r) => $r['order_qty'] > 0)
            ->sortBy([['priority', 'asc'], ['order_qty', 'desc']])->take(15)->values();

        // Allocate marketing budget proportionally to ROAS (return on ad spend).
        $roas = collect($d['channel_roas']);
        $total = max(0.0001, $roas->sum('roas'));
        $allocation = $roas->map(fn ($c) => [
            'channel' => $c['channel'],
            'roas' => $c['roas'],
            'share_pct' => round($c['roas'] / $total * 100, 1),
            'budget' => round($budget * $c['roas'] / $total, 2),
            'expected_revenue' => round($budget * $c['roas'] / $total * $c['roas'], 2),
        ])->values();

        $best = $roas->first();
        $worst = $roas->last();
        $totalSpend = max(1, $roas->sum('spend'));
        $currentExpected = $roas->sum(fn ($c) => $budget * ($c['spend'] / $totalSpend) * $c['roas']);
        $optimizedExpected = $allocation->sum('expected_revenue');

        $topCat = $d['by_category'][0] ?? null;
        $actions = [
            "Shift ad budget toward {$best['channel']} (₱{$best['roas']} revenue per ₱1 spent) and reduce {$worst['channel']} (₱{$worst['roas']}).",
            $restock->count() ? "Restock {$restock->count()} products now; ".$restock->where('priority', 'High')->count().' are at or below reorder level.' : 'Stock levels are sufficient for the forecast.',
            $topCat ? "Keep the {$topCat['name']} category well stocked: it is the biggest revenue source." : 'Add products to start analysis.',
            'Expect '.$p['trend'].' revenue; plan purchasing around the 3-month forecast.',
        ];

        return [
            'budget' => $budget,
            'restock' => $restock,
            'allocation' => $allocation,
            'current_mix_expected_revenue' => round($currentExpected, 2),
            'optimized_expected_revenue' => round($optimizedExpected, 2),
            'uplift' => round($optimizedExpected - $currentExpected, 2),
            'actions' => $actions,
        ];
    }

    private function linreg(array $x, array $y): array
    {
        $n = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0;
        foreach ($x as $i => $xi) {
            $sxy += ($xi - $mx) * ($y[$i] - $my);
            $sxx += ($xi - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }
        $slope = $sxx ? $sxy / $sxx : 0;
        $r2 = ($sxx && $syy) ? ($sxy ** 2) / ($sxx * $syy) : 0;

        return [$slope, $my - $slope * $mx, $r2];
    }

    private function correlation(array $a, array $b): float
    {
        $n = count($a);
        if ($n < 2) {
            return 0;
        }
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $num = $da = $db = 0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($a[$i] - $ma) * ($b[$i] - $mb);
            $da += ($a[$i] - $ma) ** 2;
            $db += ($b[$i] - $mb) ** 2;
        }

        return ($da && $db) ? $num / sqrt($da * $db) : 0;
    }
}
