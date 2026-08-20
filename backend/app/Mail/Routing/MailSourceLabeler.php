<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

/**
 * Maps runtime-detected wp_mail source-plugin slugs to human labels for the routing picker.
 *
 * Slugs are the plugin directory names MailSourceDetector records; labels come from the plugin
 * headers exposed by get_plugins(). mu:/theme: prefixed slugs and undetected plugins pass through.
 */
final class MailSourceLabeler
{
    /**
     * @var array<string,string> plugin directory slug => human name
     */
    private array $directory;

    /**
     * @param null|array<string,array<string,mixed>> $plugins get_plugins() output; injected in tests
     */
    public function __construct(?array $plugins = null)
    {
        $this->directory = $this->indexByDirectory($plugins ?? $this->loadPlugins());
    }

    /**
     * Turns detected source slugs into picker options carrying a friendly label.
     *
     * @param array<int,string> $slugs
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function options(array $slugs): array
    {
        return array_map(function (string $slug): array {
            return ['value' => $slug, 'label' => $this->directory[$slug] ?? $slug];
        }, $slugs);
    }

    /**
     * Indexes get_plugins() by the plugin directory slug MailSourceDetector emits.
     *
     * @param array<string,array<string,mixed>> $plugins
     *
     * @return array<string,string>
     */
    private function indexByDirectory(array $plugins): array
    {
        $directory = [];
        foreach ($plugins as $file => $data) {
            $slash = strpos($file, '/');
            $dir   = $slash === false ? $file : substr($file, 0, $slash);
            $name  = isset($data['Name']) && $data['Name'] !== '' ? (string) $data['Name'] : $dir;

            if (!isset($directory[$dir])) {
                $directory[$dir] = $name;
            }
        }

        return $directory;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadPlugins(): array
    {
        if (!\function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }
}
