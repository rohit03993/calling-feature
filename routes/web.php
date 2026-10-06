<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response('Call processing service', 200);
});
