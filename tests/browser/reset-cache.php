<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// This file is a local test harness, never a production maintenance command.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || config('database.default') !== 'sqlite'
    || ! in_array(config('cache.default'), ['database', 'file', 'array'], true)) {
    fwrite(STDERR, "Browser fixtures require an isolated local/testing SQLite database and local cache.\n");
    exit(1);
}
Artisan::call('cache:clear');
