<?php

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Route;
use BitApps\SMTP\HTTP\Controllers\OAuthController;

Route::get('oauth/callback', [OAuthController::class, 'callback']);
