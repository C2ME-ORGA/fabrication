<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\GeneratedDocumentController;
use App\Http\Controllers\WorkOrderBomImportDownloadController;
use App\Http\Middleware\AuthenticateGeneratedDocument;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documents/versions/{version}/download', DocumentDownloadController::class)
    ->middleware(FilamentAuthenticate::class)
    ->name('documents.versions.download');

Route::get('/work-orders/bom-imports/{import}/download', WorkOrderBomImportDownloadController::class)
    ->middleware(FilamentAuthenticate::class)
    ->name('work-orders.bom-imports.download');

Route::get('/generated-documents/{generatedDocument}/view', [GeneratedDocumentController::class, 'view'])
    ->middleware(AuthenticateGeneratedDocument::class)
    ->name('generated-documents.view');

Route::get('/generated-documents/{generatedDocument}/download', [GeneratedDocumentController::class, 'download'])
    ->middleware(AuthenticateGeneratedDocument::class)
    ->name('generated-documents.download');
