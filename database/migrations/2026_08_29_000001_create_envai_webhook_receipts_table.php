<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envai_webhook_receipts', function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->primary();
            $table->string('payload_hash', 64);
            $table->string('event_id', 191);
            $table->string('event_type', 191)->nullable();
            $table->string('request_id', 191)->nullable();
            $table->string('status', 32);
            $table->string('received_at', 64);
            $table->string('updated_at', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envai_webhook_receipts');
    }
};
