<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('preserves an inbound X-Request-Id', function () {
    $middleware = new AssignRequestId;
    $request = Request::create('/');
    $request->headers->set('X-Request-Id', 'abc-123');

    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->headers->get('X-Request-Id'))->toBe('abc-123');
});

it('mints a request id when none is provided', function () {
    $middleware = new AssignRequestId;
    $response = $middleware->handle(Request::create('/'), fn () => new Response('ok'));

    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});
