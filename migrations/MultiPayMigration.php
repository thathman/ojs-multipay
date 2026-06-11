<?php

/**
 * @file plugins/paymethod/multipay/migrations/MultiPayMigration.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MultiPayMigration
 *
 * @brief Describe database table structures.
 */

namespace APP\plugins\paymethod\multipay\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MultiPayMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Webhook Logs
        if (!Schema::hasTable('multipay_webhook_logs')) {
            Schema::create('multipay_webhook_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->string('gateway');
                $table->string('event_type');
                $table->string('reference')->nullable();
                $table->boolean('verified')->default(false);
                $table->text('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['context_id', 'gateway'], 'multipay_webhook_logs_idx');
            });
        }

        // Transactions
        if (!Schema::hasTable('multipay_transactions')) {
            Schema::create('multipay_transactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->bigInteger('queued_payment_id');
                $table->bigInteger('completed_payment_id')->nullable();
                $table->string('gateway');
                $table->string('reference');
                $table->string('provider_tx_id')->nullable();
                $table->string('status');
                $table->decimal('amount', 10, 2);
                $table->string('currency', 3);
                $table->string('trace_id')->nullable();
                $table->timestamps();

                $table->unique(['gateway', 'reference'], 'multipay_transactions_ref_unique');
                $table->index(['context_id', 'queued_payment_id'], 'multipay_transactions_qp_idx');
            });
        }

        // Idempotency
        if (!Schema::hasTable('multipay_idempotency')) {
            Schema::create('multipay_idempotency', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->string('gateway');
                $table->string('dedupe_key');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['gateway', 'dedupe_key'], 'multipay_idempotency_unique');
            });
        }

        if (!Schema::hasTable('multipay_refunds')) {
            Schema::create('multipay_refunds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->string('gateway');
                $table->string('reference');
                $table->string('provider_tx_id');
                $table->decimal('amount', 10, 2);
                $table->string('currency', 3);
                $table->string('status');
                $table->text('response_payload')->nullable();
                $table->timestamps();
                $table->index(['context_id', 'gateway'], 'multipay_refunds_ctx_gateway_idx');
            });
        }

        if (!Schema::hasTable('multipay_reconciliation_jobs')) {
            Schema::create('multipay_reconciliation_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->string('job_type')->default('snapshot');
                $table->string('status')->default('completed');
                $table->mediumText('result_json')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['context_id', 'created_at'], 'multipay_reconciliation_jobs_ctx_idx');
            });
        }

        if (!Schema::hasTable('multipay_recurring_profiles')) {
            Schema::create('multipay_recurring_profiles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->bigInteger('user_id');
                $table->string('gateway');
                $table->string('provider_customer_id')->nullable();
                $table->string('provider_plan_id')->nullable();
                $table->string('currency', 3);
                $table->decimal('amount', 10, 2);
                $table->string('interval')->default('monthly');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['context_id', 'user_id'], 'multipay_recurring_profiles_ctx_user_idx');
            });
        }

        if (!Schema::hasTable('multipay_settlement_reports')) {
            Schema::create('multipay_settlement_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->date('period_start');
                $table->date('period_end');
                $table->mediumText('summary_json');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['context_id', 'period_start', 'period_end'], 'multipay_settlement_reports_ctx_period_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multipay_settlement_reports');
        Schema::dropIfExists('multipay_recurring_profiles');
        Schema::dropIfExists('multipay_reconciliation_jobs');
        Schema::dropIfExists('multipay_refunds');
        Schema::dropIfExists('multipay_idempotency');
        Schema::dropIfExists('multipay_transactions');
        Schema::dropIfExists('multipay_webhook_logs');
    }
}
