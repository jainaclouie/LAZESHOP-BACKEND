<?php

namespace Database\Seeders;

use App\Models\{Campaign, Customer, Product, Sale, Supplier, User};
use Carbon\Carbon;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $f = Factory::create('en_PH');
        $f->seed(2026);
        mt_srand(2026);

        User::updateOrCreate(['email' => 'admin@laze.test'], [
            'name' => 'Laze Admin',
            'password' => Hash::make('password'),
        ]);

        $cities = ['Cebu City', 'Mandaue', 'Lapu-Lapu', 'Talisay', 'Consolacion', 'Minglanilla', 'Danao'];

        // 1) Suppliers (50)
        for ($i = 0; $i < 50; $i++) {
            Supplier::create([
                'name' => $f->company().' Vape Trading',
                'contact_person' => $f->name(),
                'email' => $f->unique()->safeEmail(),
                'city' => $f->randomElement(['Manila', 'Quezon City', 'Cebu City', 'Davao', 'Makati', 'Pasig']),
                'lead_time_days' => mt_rand(3, 14),
            ]);
        }

        // 2) Products (50)
        $catalog = [
            'Disposable' => [['Lost Mary', 'BM6000', 650, 420], ['Elf Bar', 'BC5000', 600, 390], ['Hyde', 'Edge Rave', 550, 350], ['Geek Bar', 'Pulse', 700, 460], ['Vozol', 'Star 8000', 620, 400]],
            'Pod System' => [['SMOK', 'Novo 5', 1200, 800], ['Vaporesso', 'XROS 3', 1500, 1000], ['GeekVape', 'Wenax M1', 1350, 900], ['Oxva', 'Xlim Pro', 1700, 1150]],
            'Mod Kit' => [['Voopoo', 'Drag X Plus', 3200, 2300], ['GeekVape', 'Aegis Legend 2', 3800, 2700], ['Vaporesso', 'GEN 200', 3500, 2500], ['Uwell', 'Caliburn G3', 2400, 1700]],
            'E-Liquid' => [['Naked 100', 'Hawaiian Pog 60ml', 850, 520], ['Juice Head', 'Peach Pear 100ml', 900, 560], ['Nasty Juice', 'Slow Blow 60ml', 800, 500], ['Pachamama', 'Mango Tango 60ml', 950, 600], ['Salt Nic', 'Mint Ice 30ml', 450, 270]],
            'Accessories' => [['SMOK', 'Replacement Coil 5pk', 450, 260], ['Vaporesso', 'GTX Pod 2pk', 380, 220], ['Generic', 'USB-C Charger', 250, 120], ['Generic', '18650 Battery', 400, 250], ['Generic', 'Carry Case', 300, 150]],
        ];
        $flat = [];
        foreach ($catalog as $cat => $items) {
            foreach ($items as $it) {
                $flat[] = [$cat, ...$it];
            }
        }
        $flavors = ['Mint', 'Mango', 'Grape', 'Watermelon', 'Tobacco', 'Blueberry', 'Lychee', 'Cola'];
        for ($i = 0; $i < 50; $i++) {
            [$cat, $brand, $model, $price, $cost] = $flat[$i % count($flat)];
            $suffix = $i >= count($flat) ? ' - '.$flavors[$i % count($flavors)] : '';
            Product::create([
                'name' => "$brand $model$suffix",
                'category' => $cat,
                'brand' => $brand,
                'supplier_id' => mt_rand(1, 50),
                'price' => $price,
                'cost' => $cost,
                'stock' => mt_rand(0, 90),
                'reorder_level' => mt_rand(10, 25),
            ]);
        }

        // 3) Customers (60) - all 18+
        for ($i = 0; $i < 60; $i++) {
            Customer::create([
                'name' => $f->name(),
                'email' => $f->unique()->safeEmail(),
                'phone' => '09'.mt_rand(100000000, 999999999),
                'age' => mt_rand(18, 45),
                'city' => $f->randomElement($cities),
                'joined_at' => Carbon::now()->subDays(mt_rand(10, 400))->toDateString(),
            ]);
        }

        // 4) Campaigns (50) - different channels have different effectiveness
        $channels = ['Facebook Ads', 'Instagram', 'TikTok', 'Walk-in Promo', 'Referral'];
        for ($i = 0; $i < 50; $i++) {
            $ch = $channels[$i % 5];
            $start = Carbon::now()->subDays(mt_rand(10, 350));
            Campaign::create([
                'name' => "$ch Promo ".($i + 1),
                'channel' => $ch,
                'spend' => mt_rand(15, 80) * 100,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(mt_rand(14, 30))->toDateString(),
            ]);
        }

        // 5) Sales (320) - growing trend, campaign attribution
        $weight = ['Facebook Ads' => 1.0, 'Instagram' => 1.3, 'TikTok' => 1.8, 'Walk-in Promo' => 0.8, 'Referral' => 2.2];
        $campaigns = Campaign::all();
        $products = Product::all();
        for ($i = 0; $i < 320; $i++) {
            $daysAgo = (int) (364 * (1 - sqrt(mt_rand() / mt_getrandmax())));
            $date = Carbon::now()->subDays($daysAgo);
            $active = $campaigns->filter(fn ($c) => $date->between($c->start_date, $c->end_date));
            $campaign = null;
            if ($active->isNotEmpty() && mt_rand(0, 100) < 65) {
                $campaign = $active->random();
                if (mt_rand() / mt_getrandmax() > $weight[$campaign->channel] / 2.2) {
                    $campaign = null;
                }
            }
            $product = $products->random();
            $qty = mt_rand(1, 100) > 85 ? mt_rand(3, 6) : mt_rand(1, 2);
            Sale::create([
                'customer_id' => mt_rand(1, 60),
                'product_id' => $product->id,
                'campaign_id' => $campaign?->id,
                'quantity' => $qty,
                'total' => $product->price * $qty,
                'sales_channel' => mt_rand(0, 100) < 70 ? 'Store' : 'Online',
                'sold_at' => $date->toDateString(),
            ]);
        }
    }
}
