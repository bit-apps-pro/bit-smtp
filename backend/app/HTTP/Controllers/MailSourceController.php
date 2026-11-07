<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Config;
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

    private ?MailSourceLabeler $labeler;

    /**
     * Optional collaborators let tests inject doubles; production builds both lazily.
     */
    public function __construct(?MailAnalyticsRepository $repository = null, ?MailSourceLabeler $labeler = null)
    {
        $this->repository = $repository;
        $this->labeler    = $labeler;
    }

    /**
     * Returns { sources, installed }: plugins seen sending mail, then other installed plugins, as { value, label }; Bit SMTP copies are left out.
     */
    public function index(): Response
    {
        $seen = $this->repository()->distinctSourcePlugins();
        if ($seen instanceof WP_Error) {
            return Response::error(__('Failed to load mail sources', 'bit-smtp'));
        }

        $labeler = $this->labeler ?? new MailSourceLabeler();
        $ownSlug = \dirname(Config::get('BASENAME'));
        $copies  = $labeler->copySlugs($ownSlug);
        $slugs   = array_values(array_diff($seen, $copies));

        return Response::success([
            'sources'   => $labeler->options($slugs),
            'installed' => $labeler->installed([...$slugs, $ownSlug, ...$copies]),
        ]);
    }

    /**
     * The injected repository, or the plugin's shared container singleton on first use.
     */
    private function repository(): MailAnalyticsRepository
    {
        return $this->repository ??= Plugin::instance()->app()->make(MailAnalyticsRepository::class);
    }
}
