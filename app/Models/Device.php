<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'device_code',
        'name',
        'location',
        'description',
        'api_token_hash',
        'api_token_prefix',
        'sensors_info',
        'send_interval_sec',
        'offline_timeout_sec',
        'last_seen_at',
        'last_tx_status',
        'last_tx_error',
        'is_active',
        'created_by',
    ];

    /**
     * Attributes hidden from serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'api_token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sensors_info' => 'array',
            'send_interval_sec' => 'integer',
            'offline_timeout_sec' => 'integer',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * All sensor readings recorded by this device.
     */
    public function readings(): HasMany
    {
        return $this->hasMany(SensorReading::class, 'device_id');
    }

    /**
     * Most recent sensor reading from this device.
     */
    public function latestReading(): HasOne
    {
        return $this->hasOne(SensorReading::class, 'device_id')->latestOfMany('recorded_at');
    }

    /**
     * Admin user who registered this device.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether the device is currently online based on last_seen_at.
     */
    public function isOnline(?int $overrideTimeoutSeconds = null): bool
    {
        if (! $this->is_active || $this->last_seen_at === null) {
            return false;
        }

        $timeout = $overrideTimeoutSeconds ?? $this->offline_timeout_sec ?? 30;

        return $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($timeout));
    }

    /**
     * Generate a new plaintext API token and store its SHA-256 hash on the model.
     */
    public function issueNewApiToken(): string
    {
        $plainToken = 'slr_' . Str::random(40);

        $this->api_token_hash = hash('sha256', $plainToken);
        $this->api_token_prefix = substr($plainToken, 0, 12);

        return $plainToken;
    }

    /**
     * Verify a plaintext API token against the stored SHA-256 hash.
     */
    public function matchesApiToken(string $plainToken): bool
    {
        return hash_equals($this->api_token_hash, hash('sha256', $plainToken));
    }
}
