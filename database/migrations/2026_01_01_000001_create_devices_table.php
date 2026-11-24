<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 50)->unique();
            $table->string('name', 100);
            $table->string('location', 150)->nullable();
            $table->text('description')->nullable();
            $table->char('api_token_hash', 64)->unique();
            $table->string('api_token_prefix', 12)->nullable();
            $table->json('sensors_info')->nullable();
            $table->unsignedInteger('send_interval_sec')->default(5);
            $table->unsignedInteger('offline_timeout_sec')->default(30);
            $table->timestamp('last_seen_at')->nullable()->index('idx_devices_last_seen_at');
            $table->enum('last_tx_status', ['success', 'error', 'never'])->default('never');
            $table->string('last_tx_error', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
