<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use DynamicAllocations\Controller;
Route::get('/options', [Controller::class, 'options']);
Route::post('/create', [Controller::class, 'create'])->middleware('throttle:10,1');
