<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
echo json_encode(['driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName()], JSON_PRETTY_PRINT).PHP_EOL;
