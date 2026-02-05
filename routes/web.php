<?php

use App\Http\Controllers\ImageController;
use Illuminate\Support\Facades\Route;


Route::get('/{bucket}/{size}/{path}', [ImageController::class, 'resize'])
    ->where([
        'size' => '^(\d+x\d+)(!|%21)?$',
        'path' => '.*',
    ])
    ->name('image.resize');

Route::get('/{bucket}/{path}', [ImageController::class, 'show'])
    ->where(['path' => '.*'])
    ->name('image.show');