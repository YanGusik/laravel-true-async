<?php

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "Bootstrapping...\n";
$kernel->bootstrap();
echo "Bootstrap OK\n";

$request = Illuminate\Http\Request::create('/ping');
echo "Handling request...\n";

$response = $kernel->handle($request);
echo "Response: {$response->getStatusCode()} {$response->getContent()}\n";
