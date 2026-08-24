<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Routing\MailSourceLabeler;
use BitApps\SMTP\Plugin;
use WP_Error;

/**
 * Exposes the distinct wp_mail source plugins detected at runtime for the routing condition picker.
 */
class MailSourceController
{
    private ?MailAnalyticsRepository $repository;

    public function __construct(?MailAnalyticsRepository $repository = null)
    {
        $this->repository = $repository;
    }

    /**
     * Returns detected source plugins as { sources: [{ value: <slug>, label: <name> }] }.
     */
    public function index(): Response
    {
        $slugs = $this->repository()->distinctSourcePlugins();
        if ($slugs instanceof WP_Error) {
            return Response::error(__('Failed to load mail sources', 'bit-smtp'));
        }

        return Response::success(['sources' => (new MailSourceLabeler())->options($slugs)]);
    }

    /**
     * The injected repository, or the plugin's shared container singleton on first use.
     */
    private function repository(): MailAnalyticsRepository
    {
        return $this->repository ??= Plugin::instance()->app()->make(MailAnalyticsRepository::class);
    }
}
