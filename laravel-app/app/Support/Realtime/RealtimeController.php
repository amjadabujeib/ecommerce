<?php

namespace App\Support\Realtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RealtimeController
{
    public function configuration(): JsonResponse
    {
        $url = (string) config('app.url');
        $secure = config('realtime.public_scheme', parse_url($url, PHP_URL_SCHEME)) === 'https';
        $port = (int) (config('realtime.public_port') ?: parse_url($url, PHP_URL_PORT) ?: ($secure ? 443 : 80));

        return response()->json(['data' => [
            'enabled' => (bool) config('messaging.enabled'),
            'key' => config('broadcasting.connections.reverb.key'),
            'wsHost' => config('realtime.public_host') ?: parse_url($url, PHP_URL_HOST),
            'wsPort' => $port, 'wssPort' => $port, 'forceTLS' => $secure,
            'authEndpoint' => '/api/v1/realtime/auth',
        ]]);
    }

    public function authorizeChannel(Request $request, RealtimeAccess $access): JsonResponse
    {
        abort_unless(config('messaging.enabled'), 404);
        $values = $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+\.[0-9]+$/D'],
            'channel_name' => ['required', 'string', 'max:120'],
        ]);
        abort_unless($request->bearerToken(), 401);

        return response()->json($access->authorize(
            $values['socket_id'], $values['channel_name'], $request->bearerToken(),
            (int) $request->user()->getAuthIdentifier(),
        ));
    }
}
