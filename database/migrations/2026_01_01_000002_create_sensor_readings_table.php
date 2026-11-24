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
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')
                ->constrained('devices')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->decimal('voltage', 7, 3);
            $table->decimal('current', 9, 3);
            $table->decimal('power', 9, 4);
            $table->decimal('temperature', 5, 2);
            $table->decimal('lux', 10, 2);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('device_id', 'idx_sensor_readings_device_id');
            $table->index('recorded_at', 'idx_sensor_readings_recorded_at');
            $table->index(['device_id', 'recorded_at'], 'idx_sensor_readings_device_recorded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
