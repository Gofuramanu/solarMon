<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SystemSetting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'setting_key',
        'setting_value',
        'value_type',
        'description',
        'updated_by',
    ];

    /**
     * Admin user who last updated this setting.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Retrieve a typed setting value by key with short-TTL cache and fallback.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $resolver = static function () use ($key): ?array {
            $setting = static::query()->where('setting_key', $key)->first();
            if (! $setting || $setting->setting_value === null) {
                return null;
            }

            return [
                'value' => $setting->setting_value,
                'type' => $setting->value_type,
            ];
        };

        try {
            $payload = Cache::remember("system_setting:{$key}", 60, $resolver);
        } catch (Throwable) {
            $payload = $resolver();
        }

        if ($payload === null) {
            return $default;
        }

        return match ($payload['type']) {
            'integer' => (int) $payload['value'],
            'float' => (float) $payload['value'],
            'boolean' => filter_var($payload['value'], FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $payload['value'], true) ?? $default,
            default => $payload['value'],
        };
    }

    /**
     * Create or update a setting value by key and invalidate its cache entry.
     */
    public static function setValue(string $key, mixed $value, ?int $userId = null): self
    {
        $storedValue = is_bool($value)
            ? ($value ? 'true' : 'false')
            : (is_array($value) ? json_encode($value) : (string) $value);

        $record = static::query()->updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => $storedValue,
                'updated_by' => $userId,
            ]
        );

        try {
            Cache::forget("system_setting:{$key}");
        } catch (Throwable) {
            // Safe fallback if cache store is unavailable during CLI/bootstrap
        }

        return $record;
    }
}
