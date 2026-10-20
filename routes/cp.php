<?php

use Illuminate\Support\Facades\Route;
use KareliaLabs\Spreadsheet\Http\Controllers\SpreadsheetController;

Route::prefix('spreadsheet')->name('karelia-spreadsheet.')->group(function () {
    Route::get('/', [SpreadsheetController::class, 'index'])->name('index');
    Route::get('{handle}', [SpreadsheetController::class, 'show'])->name('show');
    Route::get('{handle}/rows', [SpreadsheetController::class, 'rows'])->name('rows');
    Route::post('{handle}/save', [SpreadsheetController::class, 'save'])->name('save');
    Route::get('{handle}/lookup', [SpreadsheetController::class, 'lookup'])->name('lookup');
});
