<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Sendproof\Core\Stores\Guard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route `preview: false`: an operation the API author knows writes to a guarded store.
 *
 * The workbench then shows "Preview unavailable" with the reason before anyone tries, and a
 * Preview of the route is refused (409, never confirmed) without running the handler. Send still
 * runs it. The mark never changes how the route behaves, so this middleware only passes the
 * request on; the transport and discovery read its parameter. A binding never infers the mark.
 *
 * Add it with `->sendproofPreview(false, 'Clears the Redis session')`.
 */
final class PreviewUnavailable
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * The middleware string for a route: this class plus the reason and stores, packed into the one
     * parameter Laravel passes through (URL-safe base64 of JSON, so no comma or colon appears in it).
     *
     * @param list<string> $stores Store ids the operation writes, such as `redis`
     * @throws \InvalidArgumentException when neither a reason nor a store is given, or a value is malformed
     */
    public static function using(?string $reason = null, array $stores = []): string
    {
        foreach ($stores as $store) {
            if (!is_string($store) || preg_match('/^[a-z][a-z0-9]*$/', $store) !== 1) {
                throw new \InvalidArgumentException('List the stores an unavailable operation writes as store ids such as "redis".');
            }
        }
        $text = $reason ?? ($stores !== [] ? Guard::writeReason($stores[0]) : null);
        if ($text === null || trim($text) === '' || mb_strlen($text) > 512) {
            throw new \InvalidArgumentException('Give an unavailable operation a reason, or the stores it writes.');
        }
        $payload = json_encode(['reason' => $text] + ($stores === [] ? [] : ['stores' => array_values($stores)]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::class . ':' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Reads the mark back from a route's middleware string; null when it isn't this middleware.
     *
     * @return array{reason:string,stores?:list<string>}|null
     */
    public static function decode(mixed $middleware): ?array
    {
        if (!is_string($middleware) || !str_starts_with($middleware, self::class . ':')) {
            return null;
        }
        $json = base64_decode(strtr(substr($middleware, strlen(self::class) + 1), '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) && is_string($data['reason'] ?? null) ? $data : null;
    }
}
