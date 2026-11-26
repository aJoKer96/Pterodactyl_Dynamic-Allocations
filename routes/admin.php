<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use DynamicAllocations\Controller;
Route::get('/options', [Controller::class, 'options']);
Route::post('/create', [Controller::class, 'create'])->middleware('throttle:10,1');
Route::put('/eggs/{egg:id}/port-preset', [Controller::class, 'savePreset'])->middleware('throttle:30,1');
Route::delete('/eggs/{egg:id}/port-preset', [Controller::class, 'deletePreset'])->middleware('throttle:30,1');
Route::put('/nodes/{node:id}/bind-ip', [Controller::class, 'saveNodeAddress'])->middleware('throttle:30,1');
Route::delete('/nodes/{node:id}/bind-ip', [Controller::class, 'resetNodeAddress'])->middleware('throttle:30,1');
Route::get('/network/options', [Controller::class, 'networkOptions']);
Route::get('/servers/{server:admin_identifier}/network', [Controller::class, 'serverNetwork']);
Route::patch('/servers/{server:admin_identifier}/network', [Controller::class, 'update'])->middleware('throttle:10,1');
