<?php

use App\Http\Controllers\Admin\AccessibleRepositorySyncController;
use App\Http\Controllers\Admin\PreferenceController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\RuntimeEvaluationController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceLockfileSyncController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TeamTestMessageController;
use App\Http\Controllers\Admin\TeamWebhookController;
use App\Http\Controllers\Admin\TechnologyCatalogSyncController;
use App\Http\Controllers\Admin\TechnologyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Admin screens, built with Inertia + React.
 */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [SessionController::class, 'create'])->name('login');
        Route::post('login', [SessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
    });

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

        Route::redirect('/', '/admin/projects')->name('home');

        Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::get('search', SearchController::class)->name('search');

        Route::resource('projects', ProjectController::class);
        Route::resource('services', ServiceController::class)->except('show');
        Route::post('services/{service}/lockfile-sync', [ServiceLockfileSyncController::class, 'store'])->name('services.lockfile-sync.store');
        Route::post('repositories/sync', [AccessibleRepositorySyncController::class, 'store'])->name('repositories.sync.store');
        Route::get('runtime-evaluation', RuntimeEvaluationController::class)->name('runtime-evaluation');

        Route::resource('teams', TeamController::class)->except('show');
        Route::put('teams/{team}/webhook', [TeamWebhookController::class, 'update'])->name('teams.webhook.update');
        Route::post('teams/{team}/test-message', [TeamTestMessageController::class, 'store'])->name('teams.test-message.store');

        Route::resource('technologies', TechnologyController::class)->only(['index', 'show']);
        Route::post('technologies/{technology}/sync', [TechnologyCatalogSyncController::class, 'store'])->name('technologies.sync.store');
    });
});
