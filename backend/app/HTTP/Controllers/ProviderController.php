<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Plugin;

class ProviderController
{
    public function index()
    {
        return Response::success(['providers' => Plugin::instance()->providerRegistry()->metadata()]);
    }
}
