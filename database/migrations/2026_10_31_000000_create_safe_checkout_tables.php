<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('safe-checkout.tables.payments', 'checkout_payments'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('gateway', 32);
            $table->string('gateway_reference', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->nullableMorphs('payable');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending')->index();
            $table->text('checkout_url')->nullable();
            $table->string('description')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_reference']);
        });

        Schema::create(config('safe-checkout.tables.webhooks', 'checkout_webhook_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id', 191);
            $table->string('reference', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('safe-checkout.tables.webhooks', 'checkout_webhook_events'));
        Schema::dropIfExists(config('safe-checkout.tables.payments', 'checkout_payments'));
    }
};
