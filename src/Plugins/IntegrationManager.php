<?php
/**
 * Third-party plugin integration manager.
 *
 * This file discovers built-in plugin integrations and registers their
 * annotated hooks when notifications and related settings are enabled.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Notifications\MessageFactory;
use KonstantinSorokin\Telegram\Plugins\Attributes\Hook;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Registers third-party plugin notification integrations.
 */
final readonly class IntegrationManager {

    private const string CACHE_FILE = 'cache/plugin-integrations.php';

    private MessageFactory $messages;

    public function __construct(
        private SettingsRepository $settings,
        private MessageSender $sender
    ) {
        $this->messages = new MessageFactory($settings);
    }

    /**
     * Register enabled integration hooks.
     *
     * @return void
     */
    public function boot(): void {
        foreach ($this->integrations() as $integration) {
            if ($integration->isAvailable()) {
                if ($integration instanceof BootablePluginIntegration) {
                    $integration->boot();
                }

                $this->registerHooks($integration);
            }
        }
    }

    /**
     * Discover integrations and let projects add their own.
     *
     * @return array<int,PluginIntegration>
     */
    private function integrations(): array {
        $integrations = $this->discoverIntegrations();

        /**
         * Filter third-party plugin integrations.
         *
         * @param array<int,PluginIntegration> $integrations Discovered integrations.
         * @param SettingsRepository          $settings     Settings repository.
         * @param MessageSender               $sender       Message sender.
         * @param MessageFactory              $messages     Message factory.
         */
        $integrations = apply_filters('ks_telegram_plugin_integrations', $integrations, $this->settings, $this->sender, $this->messages);
        $integrations = is_array($integrations) ? $integrations : [];

        return array_values(
            array_filter(
                $integrations,
                static fn (mixed $integration): bool => $integration instanceof PluginIntegration
            )
        );
    }

    /**
     * Discover integration classes from src/Plugins.
     *
     * @return array<int,PluginIntegration>
     */
    private function discoverIntegrations(): array {
        $classes = $this->integrationClasses();

        return array_values(
            array_filter(
                array_map([$this, 'instantiateIntegration'], $classes)
            )
        );
    }

    /**
     * Get integration class names from cache or live discovery.
     *
     * @return array<int,class-string>
     */
    private function integrationClasses(): array {
        if (! $this->isDebug()) {
            $cached = $this->cachedIntegrationClasses();
            if (null !== $cached) {
                return $cached;
            }
        }

        $classes = $this->scanIntegrationClasses();
        $this->writeIntegrationCache($classes);

        return $classes;
    }

    /**
     * Discover integration class names from src/Plugins.
     *
     * @return array<int,class-string>
     */
    private function scanIntegrationClasses(): array {
        $directory = KS_TELEGRAM_DIR . 'src/Plugins';
        if (! is_dir($directory)) {
            return [];
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (Throwable $exception) {
            do_action('ks_telegram_plugin_integration_discovery_error', $directory, $exception);

            return [];
        }

        $classes = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = $this->classNameFromPath((string) $file->getPathname(), $directory);
            if (null !== $class) {
                $classes[] = $class;
            }
        }

        return $this->validIntegrationClasses($classes);
    }

    /**
     * Read integration class names from the generated cache file.
     *
     * @return array<int,class-string>|null
     */
    private function cachedIntegrationClasses(): ?array {
        $path = $this->cachePath();
        if (! is_readable($path)) {
            return null;
        }

        try {
            $cache = include $path;
        } catch (Throwable $exception) {
            do_action('ks_telegram_plugin_integration_cache_error', $path, $exception);

            return null;
        }
        if (
            ! is_array($cache)
            || KS_TELEGRAM_VERSION !== (string) ($cache['version'] ?? '')
            || ! isset($cache['classes'])
            || ! is_array($cache['classes'])
        ) {
            return null;
        }

        return $this->validIntegrationClasses($cache['classes']);
    }

    /**
     * Write integration class names to a generated PHP cache file.
     *
     * @param array<int,class-string> $classes Integration class names.
     *
     * @return void
     */
    private function writeIntegrationCache(array $classes): void {
        $path      = $this->cachePath();
        $directory = dirname($path);

        if (! wp_mkdir_p($directory)) {
            return;
        }

        $content = "<?php\n"
            . "/**\n"
            . " * Generated plugin integration discovery cache.\n"
            . " *\n"
            . " * @package KS_Telegram\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "defined('ABSPATH') || exit;\n\n"
            . "return [\n"
            . "    'version' => '" . $this->phpString(KS_TELEGRAM_VERSION) . "',\n"
            . "    'generated' => " . time() . ",\n"
            . "    'classes' => [\n";

        foreach ($classes as $class) {
            $content .= "        '" . $this->phpString($class) . "',\n";
        }

        $content .= "    ],\n];\n";

        if (false === file_put_contents($path, $content, LOCK_EX)) {
            do_action('ks_telegram_plugin_integration_cache_write_failed', $path);
        }
    }

    /**
     * Filter cached class names to currently loadable integrations.
     *
     * @param array<int,mixed> $classes Raw cached class names.
     *
     * @return array<int,class-string>
     */
    private function validIntegrationClasses(array $classes): array {
        $valid = [];

        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable $exception) {
                do_action('ks_telegram_plugin_integration_discovery_error', $class, $exception);

                continue;
            }

            if ($reflection->isInstantiable() && $reflection->implementsInterface(PluginIntegration::class)) {
                $valid[] = $class;
            }
        }

        sort($valid);

        return array_values(array_unique($valid));
    }

    /**
     * Get the generated cache file path.
     *
     * @return string
     */
    private function cachePath(): string {
        return KS_TELEGRAM_DIR . self::CACHE_FILE;
    }

    /**
     * Check whether WordPress debug mode is enabled.
     *
     * @return bool
     */
    private function isDebug(): bool {
        return defined('WP_DEBUG') && true === WP_DEBUG;
    }

    /**
     * Escape a string for a generated single-quoted PHP string literal.
     *
     * @param string $value Raw string.
     *
     * @return string
     */
    private function phpString(string $value): string {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Build a PSR-4 class name from a plugin integration file path.
     *
     * @param string $path      PHP file path.
     * @param string $directory Integration directory.
     *
     * @return class-string|null
     */
    private function classNameFromPath(string $path, string $directory): ?string {
        $relative = substr($path, strlen($directory) + 1);
        if (! is_string($relative) || ! str_ends_with($relative, '.php')) {
            return null;
        }

        $relative = substr($relative, 0, -4);
        $relative = str_replace(['/', '\\'], '\\', $relative);
        $class    = __NAMESPACE__ . '\\' . $relative;

        return class_exists($class) ? $class : null;
    }

    /**
     * Instantiate one integration class when its constructor is supported.
     *
     * @param class-string $class Integration class name.
     *
     * @return PluginIntegration|null
     */
    private function instantiateIntegration(string $class): ?PluginIntegration {
        try {
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(PluginIntegration::class)) {
                return null;
            }

            $constructor = $reflection->getConstructor();
            $arguments   = null === $constructor ? [] : $this->constructorArguments($constructor);
            $integration = match (true) {
                null === $constructor => $reflection->newInstance(),
                null === $arguments => null,
                default => $reflection->newInstanceArgs($arguments),
            };
        } catch (Throwable $exception) {
            do_action('ks_telegram_plugin_integration_discovery_error', $class, $exception);

            return null;
        }

        return $integration instanceof PluginIntegration ? $integration : null;
    }

    /**
     * Build constructor arguments from known shared services.
     *
     * @param ReflectionMethod $constructor Integration constructor.
     *
     * @return array<int,object>|null
     */
    private function constructorArguments(ReflectionMethod $constructor): ?array {
        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return $parameter->isDefaultValueAvailable() ? $arguments : null;
            }

            $dependency = $this->dependency($type->getName());
            if (null === $dependency) {
                return $parameter->isDefaultValueAvailable() ? $arguments : null;
            }

            $arguments[] = $dependency;
        }

        return $arguments;
    }

    /**
     * Get a shared dependency by class name.
     *
     * @param class-string $class Dependency class name.
     *
     * @return object|null
     */
    private function dependency(string $class): ?object {
        return match ($class) {
            MessageFactory::class => $this->messages,
            MessageSender::class => $this->sender,
            SettingsRepository::class => $this->settings,
            default => null,
        };
    }

    /**
     * Register attribute-defined WordPress hooks for one integration.
     *
     * @param PluginIntegration $integration Plugin integration.
     *
     * @return void
     */
    private function registerHooks(PluginIntegration $integration): void {
        $reflection = new ReflectionObject($integration);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Hook::class) as $attribute) {
                try {
                    $hook = $attribute->newInstance();
                } catch (Throwable $exception) {
                    /**
                     * Fires when a plugin integration hook attribute cannot be instantiated.
                     *
                     * @param PluginIntegration $integration Plugin integration instance.
                     * @param string            $method      Method name.
                     * @param Throwable         $exception   Attribute instantiation failure.
                     */
                    do_action('ks_telegram_plugin_integration_hook_error', $integration, $method->getName(), $exception);

                    continue;
                }

                if ($hook instanceof Hook && $this->settings->bool('notifications_enabled') && $this->settings->bool($hook->setting)) {
                    add_action($hook->name, [$integration, $method->getName()], $hook->priority, $hook->accepted_args);
                }
            }
        }
    }
}
