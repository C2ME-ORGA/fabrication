<?php

use App\Http\Controllers\DocumentDownloadController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documents/versions/{version}/download', DocumentDownloadController::class)
    ->middleware(FilamentAuthenticate::class)
    ->name('documents.versions.download');
