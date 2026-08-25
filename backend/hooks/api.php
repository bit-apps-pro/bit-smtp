<?php

if (!\defined('ABSPATH')) {
    exit;
}

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Route;
use BitApps\SMTP\HTTP\Controllers\AnalyticsController;
use BitApps\SMTP\HTTP\Controllers\ConnectionController;
use BitApps\SMTP\HTTP\Controllers\ConnectionHealthController;
use BitApps\SMTP\HTTP\Controllers\LogController;
use BitApps\SMTP\HTTP\Controllers\MailSettingsController;
use BitApps\SMTP\HTTP\Controllers\MailSourceController;
use BitApps\SMTP\HTTP\Controllers\NotificationController;
use BitApps\SMTP\HTTP\Controllers\OAuthController;
use BitApps\SMTP\HTTP\Controllers\PreferencesController;
use BitApps\SMTP\HTTP\Controllers\ProviderController;
use BitApps\SMTP\HTTP\Controllers\RetryController;
use BitApps\SMTP\HTTP\Controllers\SMTPController;
use BitApps\SMTP\HTTP\Controllers\TelemetryPopupController;

Route::group(function () {
    Route::post('mail/config/save', [SMTPController::class, 'saveMailConfig']);
    Route::get('mail/config/get', [SMTPController::class, 'index']);
    Route::post('mail/send-test', [SMTPController::class, 'sendTestEmail']);
    Route::post('mail/resend', [SMTPController::class, 'resend']);

    Route::post('telemetry/handle-permission', [TelemetryPopupController::class, 'handleTelemetryPermission']);
    Route::get('telemetry/popup-status', [TelemetryPopupController::class, 'isPopupDisabled']);

    Route::post('logs/all', [LogController::class, 'all']);
    Route::post('logs/export', [LogController::class, 'export']);
    Route::post('logs/details/{id}', [LogController::class, 'details']);
    Route::post('logs/delete', [LogController::class, 'delete']);
    Route::get('logs/is_enabled', [LogController::class, 'isEnabled']);
    Route::post('logs/toggle', [LogController::class, 'toggle']);

    Route::post('logs/update_retention', [LogController::class, 'updateRetention']);

    Route::get('mail/settings', [MailSettingsController::class, 'index']);
    Route::post('mail/settings/save', [MailSettingsController::class, 'save']);
    Route::post('mail/notifications/test', [NotificationController::class, 'test']);
    Route::get('mail/providers', [ProviderController::class, 'index']);
    Route::get('mail/routing/sources', [MailSourceController::class, 'index']);
    Route::post('mail/connections/save', [ConnectionController::class, 'save']);
    Route::post('mail/connections/webhook/create', [ConnectionController::class, 'createWebhook']);
    Route::post('mail/connections/delete', [ConnectionController::class, 'delete']);
    Route::post('mail/connections/test', [ConnectionController::class, 'test']);
    Route::get('mail/oauth/authorize', [OAuthController::class, 'authorize']);

    Route::get('preferences', [PreferencesController::class, 'index']);
    Route::post('preferences/save', [PreferencesController::class, 'save']);
    Route::get('preferences/export', [PreferencesController::class, 'export']);
    Route::post('preferences/import', [PreferencesController::class, 'import']);

    Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('analytics/deliverability', [AnalyticsController::class, 'deliverability']);
    Route::get('analytics/anomalies', [AnalyticsController::class, 'anomalies']);

    Route::get('mail/retry-queue', [RetryController::class, 'status']);
    Route::post('mail/retry-queue/flush', [RetryController::class, 'flush']);

    Route::get('mail/connections/health', [ConnectionHealthController::class, 'index']);
    Route::post('mail/connections/health/check', [ConnectionHealthController::class, 'check']);
})->middleware('cap:admin');
