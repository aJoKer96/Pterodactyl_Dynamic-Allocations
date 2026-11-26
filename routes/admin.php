<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use DynamicAllocations\Controller;
Route::get('/options', [Controller::class, 'options']);
Route::post('/create', [Controller::class, 'create'])->middleware('throttle:10,1');
Route::put('/eggs/{egg}/port-preset', [Controller::class, 'savePreset'])->middleware('throttle:30,1');
Route::delete('/eggs/{egg}/port-preset', [Controller::class, 'deletePreset'])->middleware('throttle:30,1');
Route::put('/nodes/{node}/bind-ip', [Controller::class, 'saveNodeAddress'])->middleware('throttle:30,1');
Route::delete('/nodes/{node}/bind-ip', [Controller::class, 'resetNodeAddress'])->middleware('throttle:30,1');
