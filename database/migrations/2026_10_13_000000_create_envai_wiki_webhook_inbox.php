<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envai_wiki_webhook_inbox', function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->primary();
            $table->string('event_id', 255);
            $table->string('workspace_id', 255);
            $table->string('payload_hash', 64);
            $table->string('event_type', 100);
            $table->string('received_at', 32);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envai_wiki_webhook_inbox');
    }
};
