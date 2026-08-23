<?php

declare(strict_types=1);

it('reports liveness without touching dependencies', function () {
    $this->getJson('/healthz')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'time']);
});

it('reports readiness with a per-dependency breakdown', function () {
    // The testing DB is sqlite in-memory, so the database probe always succeeds;
    // we assert the contract (shape + a healthy DB) rather than depending on a
    // live Redis being present in every environment.
    $response = $this->getJson('/readyz');

    $response->assertJsonStructure([
        'status',
        'time',
        'checks' => [
            'database' => ['ok', 'error'],
            'redis' => ['ok', 'error'],
        ],
    ]);

    expect($response->json('checks.database.ok'))->toBeTrue();
    expect($response->status())->toBeIn([200, 503]);
});
