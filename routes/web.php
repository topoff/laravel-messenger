<?php

use Illuminate\Support\Facades\Route;
use Topoff\Messenger\Http\Controllers\MailTrackingNovaController;
use Topoff\Messenger\Http\Controllers\NovaCustomMessagePreviewController;
use Topoff\Messenger\Http\Controllers\NovaMailPreviewController;
use Topoff\Messenger\Http\Controllers\SesSnsDashboardCommandController;
use Topoff\Messenger\Http\Controllers\SesSnsDashboardController;
use Topoff\Messenger\Http\Controllers\SesSnsDashboardCustomMailController;
use Topoff\Messenger\Http\Controllers\SesSnsSetupStatusController;

$novaConfig = array_replace_recursive([
    'enabled' => true,
    'preview_route' => [
        'prefix' => 'emessenger/nova',
        'middleware' => ['web', 'signed'],
    ],
], (array) config('messenger.tracking.nova', []));

if ((bool) ($novaConfig['enabled'] ?? true)) {
    $previewRoute = $novaConfig['preview_route'] ?? [];
    Route::group($previewRoute, function (): void {
        Route::get('preview/{id}', [MailTrackingNovaController::class, 'preview'])->name('messenger.tracking.nova.preview');
        Route::get('preview-message/{message}', [NovaMailPreviewController::class, 'show'])->name('messenger.tracking.nova.preview-message');
        Route::get('ses-sns-status', SesSnsSetupStatusController::class)->name('messenger.ses-sns.status');
        Route::get('ses-sns-dashboard', SesSnsDashboardController::class)->name('messenger.ses-sns.dashboard');
        Route::post('ses-sns-dashboard/commands/{command}', SesSnsDashboardCommandController::class)->name('messenger.ses-sns.dashboard.command');
        Route::get('ses-sns-dashboard/custom-mail-action', [SesSnsDashboardCustomMailController::class, 'show'])->name('messenger.ses-sns.dashboard.custom-mail');
        Route::post('ses-sns-dashboard/custom-mail-action/send', [SesSnsDashboardCustomMailController::class, 'send'])->name('messenger.ses-sns.dashboard.custom-mail.send');
        Route::post('ses-sns-dashboard/custom-mail-action/preview', [SesSnsDashboardCustomMailController::class, 'preview'])->name('messenger.ses-sns.dashboard.custom-mail.preview');
    });
}

$customPreviewRoute = config('messenger.tracking.custom_preview_route', [
    'prefix' => 'emessenger/nova',
    'middleware' => ['web', 'signed'],
]);

Route::group($customPreviewRoute, function (): void {
    Route::get('custom-preview', [NovaCustomMessagePreviewController::class, 'show'])->name('messenger.tracking.nova.custom-preview');
});
