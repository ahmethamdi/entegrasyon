<?php

use App\Providers\AdapterServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\QueueServiceProvider;

return [
    AppServiceProvider::class,
    AdapterServiceProvider::class,
    HorizonServiceProvider::class,
    QueueServiceProvider::class,
];
