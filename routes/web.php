<?php

use App\Http\Controllers\FlightController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\NonVolController;
use App\Http\Controllers\PersonnelNavigantController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Racine -> redirige vers /machines si connecte, /login sinon
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('machines.index')
        : redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Machines
    Route::get('/machines', [MachineController::class, 'index'])->name('machines.index');
    Route::get('/machines/{hcId}', [MachineController::class, 'show'])->name('machines.show');

    // Vols
    Route::get('/flights/{flight}', [FlightController::class, 'show'])->name('flights.show');
    Route::get('/flights/{flight}/pannes-conservees', [FlightController::class, 'pannesConservees'])
        ->name('flights.pannes-conservees');
    Route::get('/flights/{flight}/pannes-isolees', [FlightController::class, 'pannesIsolees'])
        ->name('flights.pannes-isolees');
    Route::get('/flights/{flight}/xml', [FlightController::class, 'downloadXml'])->name('flights.xml');

    // Non vol
    Route::get('/flights/{flight}/non-vol', [NonVolController::class, 'show'])->name('flights.non-vol');
    Route::post('/flights/{flight}/flag-as-error', [NonVolController::class, 'flag'])->name('flights.flag-as-error');

    // Upload / Imports / Dashboards
    Route::view('/upload', 'upload')->name('upload.index');
    Route::view('/imports', 'imports')->name('imports.index');
    Route::view('/dashboards', 'dashboards')->name('dashboards.index');

    // Profil (existant Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Routes Personnel Navigant (auth + middleware personnel-navigant)
Route::middleware(['auth', 'personnel-navigant'])
    ->prefix('personnel-navigant')
    ->name('personnel-navigant.')
    ->group(function () {
        Route::get('/', [PersonnelNavigantController::class, 'index'])->name('index');
        Route::get('/{hcId}', [PersonnelNavigantController::class, 'show'])->name('show');
        Route::get('/flight/{flight}/pannes', [PersonnelNavigantController::class, 'pannes'])->name('pannes');
    });

// Routes admin (auth + middleware admin)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::view('/users', 'admin.users')->name('users');
    Route::view('/audit-log', 'admin.audit-log')->name('audit-log');
});

require __DIR__.'/auth.php';
