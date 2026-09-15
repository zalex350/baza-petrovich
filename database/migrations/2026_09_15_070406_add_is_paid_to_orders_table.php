<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('status');
        });

        DB::table('orders')->where('status', 'paid')->update([
            'status' => 'processing',
            'is_paid' => true,
        ]);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'processing')->update([
            'status' => 'paid',
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }
};