<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('api_token', 64)->nullable()->index();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact_person');
            $t->string('email');
            $t->string('city');
            $t->unsignedInteger('lead_time_days')->default(7);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->unsignedTinyInteger('age');
            $t->string('city');
            $t->date('joined_at');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category');
            $t->string('brand');
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->decimal('price', 10, 2);
            $t->decimal('cost', 10, 2);
            $t->integer('stock')->default(0);
            $t->integer('reorder_level')->default(10);
            $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('channel');
            $t->decimal('spend', 10, 2);
            $t->date('start_date');
            $t->date('end_date');
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('quantity');
            $t->decimal('total', 12, 2);
            $t->string('sales_channel')->default('Store');
            $t->date('sold_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['sales', 'campaigns', 'products', 'customers', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('api_token'));
    }
};
