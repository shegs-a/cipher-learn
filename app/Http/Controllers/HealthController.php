<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and readiness probes, kept deliberately separate.
 *
 * Orchestrators (ECS/Kubernetes) treat these differently: a failing liveness
 * probe restarts the container, while a failing readiness probe only pulls it
 * out of the load-balancer rotation. Conflating them means a transient DB blip
 * would restart healthy app processes, so we never touch dependencies in
 * liveness and never claim "ready" until dependencies actually answer.
 */
final class HealthController extends Controller
{
    /**
     * Liveness: the PHP process is up and can serve a request. No dependencies.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness: every hard dependency required to serve traffic is reachable.
     * Returns 503 with per-check detail if any dependency is down.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];

        $ready = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    /**
     * Run a single dependency probe, converting any failure into a structured
     * result rather than a 500 — a readiness endpoint must always respond.
     *
     * @param  callable():mixed  $probe
     * @return array{ok: bool, error: string|null}
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
