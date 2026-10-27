<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

/**
 * Map wp_mail source-plugin slugs to human labels for routing picker.
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
     * Picker options for every installed folder plugin not in $exclude, sorted by label.
     *
     * @param array<int,string> $exclude slugs to leave out (already-seen sources, this plugin)
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function installed(array $exclude): array
    {
        $options = $this->options(array_keys(array_diff_key($this->directory, array_flip($exclude))));
        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Indexes folder plugins by the directory slug MailSourceDetector emits; single-file plugins are unattributable.
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
            if ($slash === false) {
                continue;
            }

            $dir  = substr($file, 0, $slash);
            $name = isset($data['Name']) && $data['Name'] !== '' ? (string) $data['Name'] : $dir;

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
