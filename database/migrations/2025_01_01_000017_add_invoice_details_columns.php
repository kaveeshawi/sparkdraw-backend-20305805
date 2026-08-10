<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('project_id');
            $table->json('line_items')->nullable()->after('amount');
            $table->date('due_date')->nullable()->after('status');
            $table->text('notes')->nullable()->after('due_date');
            $table->string('paypal_order_id')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'line_items', 'due_date', 'notes', 'paypal_order_id']);
        });
    }
};
