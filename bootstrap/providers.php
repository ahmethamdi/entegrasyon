<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\QueueServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    QueueServiceProvider::class,
];
