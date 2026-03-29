<?php

/** @var Illuminate\Routing\Router $router */

$router->get('/ping', fn() => response('pong'));

$router->get('/json', fn() => response()->json([
    'status' => 'ok',
    'time' => microtime(true),
    'memory' => memory_get_usage(true),
]));

$router->get('/scoped', function (Illuminate\Http\Request $request) {
    return response()->json([
        'method' => $request->method(),
        'path' => $request->path(),
        'query' => $request->query(),
    ]);
});
