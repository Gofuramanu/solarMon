<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeviceToken
{
    /**
     * Authenticate incoming ESP32 requests using Bearer token or X-API-Key header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken() ?: $request->header('X-API-Key');

        if (! is_string($plainToken) || trim($plainToken) === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'API token tidak ditemukan. Sertakan header Authorization: Bearer <token> atau X-API-Key.',
            ], 401);
        }

        $tokenHash = hash('sha256', trim($plainToken));
        $device = Device::query()->where('api_token_hash', $tokenHash)->first();

        if (! $device) {
            return response()->json([
                'status' => 'error',
                'message' => 'API token tidak valid.',
            ], 401);
        }

        if (! $device->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Perangkat sedang dinonaktifkan dan tidak dapat mengirim data.',
            ], 403);
        }

        $request->attributes->set('authenticated_device', $device);

        return $next($request);
    }
}
