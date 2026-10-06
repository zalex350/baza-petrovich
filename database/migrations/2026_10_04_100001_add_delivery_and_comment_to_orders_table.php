<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адрес доставки хранится в заказе снимком (BR-128): последующее изменение
 * или удаление адреса организации заказ не меняет. comment — комментарий
 * клиента к заказу (BR-130).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_title')->nullable()->after('organization_id');
            $table->string('delivery_address', 500)->nullable()->after('delivery_title');
            $table->string('delivery_contact_name')->nullable()->after('delivery_address');
            $table->string('delivery_contact_phone', 50)->nullable()->after('delivery_contact_name');
            $table->text('comment')->nullable()->after('delivery_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_title',
                'delivery_address',
                'delivery_contact_name',
                'delivery_contact_phone',
                'comment',
            ]);
        });
    }
};
