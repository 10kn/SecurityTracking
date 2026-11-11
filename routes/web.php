<?php

use App\Http\Controllers\Admin\AccessibleRepositorySyncController;
use App\Http\Controllers\Admin\AdminWebhookController;
use App\Http\Controllers\Admin\AdminWebhookTestMessageController;
use App\Http\Controllers\Admin\FindingAcknowledgementController;
use App\Http\Controllers\Admin\FindingController;
use App\Http\Controllers\Admin\IncidentAcknowledgementController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\IncidentMergeController;
use App\Http\Controllers\Admin\IncidentSuggestionDecisionController;
use App\Http\Controllers\Admin\IncidentUpdateController;
use App\Http\Controllers\Admin\NoticeSplitController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PreferenceController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectImportController;
use App\Http\Controllers\Admin\ProjectMonitoringPauseController;
use App\Http\Controllers\Admin\RuntimeEvaluationController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceLockfileSyncController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SystemSettingController;
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

        Route::get('/', OverviewController::class)->name('home');

        Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::get('search', SearchController::class)->name('search');

        Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
        Route::post('findings/acknowledgements', [FindingAcknowledgementController::class, 'store'])->name('findings.acknowledgements.store');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::post('incidents/{incident}/acknowledgements', [IncidentAcknowledgementController::class, 'store'])->name('incidents.acknowledgements.store');
        Route::post('incidents/{incident}/merge', [IncidentMergeController::class, 'store'])->name('incidents.merge.store');
        Route::post('incidents/{incident}/updates', [IncidentUpdateController::class, 'store'])->name('incidents.updates.store');
        Route::post('incident-suggestions/{suggestion}/decision', [IncidentSuggestionDecisionController::class, 'store'])->name('incident-suggestions.decision.store');
        Route::post('notices/{notice}/split', [NoticeSplitController::class, 'store'])->name('notices.split.store');

        Route::get('project-imports/create', [ProjectImportController::class, 'create'])->name('project-imports.create');
        Route::get('project-imports/template', [ProjectImportController::class, 'template'])->name('project-imports.template');
        Route::post('project-imports', [ProjectImportController::class, 'store'])->name('project-imports.store');
        Route::get('project-imports/{import}', [ProjectImportController::class, 'show'])->whereUuid('import')->name('project-imports.show');
        Route::post('project-imports/{import}/confirm', [ProjectImportController::class, 'confirm'])->whereUuid('import')->name('project-imports.confirm');
        Route::resource('projects', ProjectController::class);
        Route::post('projects/{project}/monitoring-pause', [ProjectMonitoringPauseController::class, 'store'])->name('projects.monitoring-pause.store');
        Route::delete('projects/{project}/monitoring-pause', [ProjectMonitoringPauseController::class, 'destroy'])->name('projects.monitoring-pause.destroy');
        Route::resource('services', ServiceController::class)->except('show');
        Route::post('services/{service}/lockfile-sync', [ServiceLockfileSyncController::class, 'store'])->name('services.lockfile-sync.store');
        Route::post('repositories/sync', [AccessibleRepositorySyncController::class, 'store'])->name('repositories.sync.store');
        Route::get('runtime-evaluation', RuntimeEvaluationController::class)->name('runtime-evaluation');

        Route::resource('teams', TeamController::class)->except('show');
        Route::put('teams/{team}/webhook', [TeamWebhookController::class, 'update'])->name('teams.webhook.update');
        Route::post('teams/{team}/test-message', [TeamTestMessageController::class, 'store'])->name('teams.test-message.store');

        Route::resource('technologies', TechnologyController::class)->only(['index', 'show']);
        Route::post('technologies/{technology}/sync', [TechnologyCatalogSyncController::class, 'store'])->name('technologies.sync.store');

        Route::get('settings', [SystemSettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SystemSettingController::class, 'update'])->name('settings.update');
        Route::put('settings/webhooks/{webhook}', [AdminWebhookController::class, 'update'])->name('settings.webhooks.update');
        Route::delete('settings/webhooks/{webhook}', [AdminWebhookController::class, 'destroy'])->name('settings.webhooks.destroy');
        Route::post('settings/webhooks/{webhook}/test-message', [AdminWebhookTestMessageController::class, 'store'])->name('settings.webhooks.test-message.store');
    });
});
