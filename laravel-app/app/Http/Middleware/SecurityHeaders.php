<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline headers, with same-origin assets permitted on the shop page.
 */
final class SecurityHeaders
{
    /**
     * @var array<string, string>
     */
    private const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($request->is('/')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'".$this->socketOrigin()."; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }

    private function socketOrigin(): string
    {
        if (! config('messaging.enabled')) {
            return '';
        }
        $host = config('realtime.public_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $scheme = config('realtime.public_scheme') === 'https' ? 'wss' : 'ws';
        $port = config('realtime.public_port') ?: parse_url((string) config('app.url'), PHP_URL_PORT);
        if (! is_string($host) || ! preg_match('/^[a-zA-Z0-9.\-]+$/D', $host)) {
            return '';
        }

        return ' '.$scheme.'://'.$host.($port ? ':'.(int) $port : '');
    }
}
