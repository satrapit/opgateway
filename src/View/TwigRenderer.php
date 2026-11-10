<?php

declare(strict_types=1);

namespace OPGateway\View;

use RuntimeException;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Strict, autoescaping Twig 3 presentation boundary.
 *
 * The loader's directory is private and fixed by trusted PHP code. Never
 * accept user-defined template paths or make templates accessible as URLs.
 * Business logic, SQL and account security decisions remain outside Twig.
 */
final class TwigRenderer
{
    private TwigEnvironment $engine;

    public function __construct(string $templateDirectory)
    {
        // Production never runs Composer. Dependencies are prepared locally
        // and placed only in the private Git checkout, outside Document Root.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload) && !is_link($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(TwigEnvironment::class)) {
            throw new RuntimeException('The Twig 3 dependency is unavailable.');
        }

        if (!is_dir($templateDirectory) || is_link($templateDirectory)) {
            throw new RuntimeException('Private Twig template directory unavailable.');
        }

        $this->engine = new TwigEnvironment(
            new FilesystemLoader($templateDirectory),
            [
                'autoescape' => 'html',
                'strict_variables' => true,
                'cache' => false,
                'debug' => false,
            ],
        );

        // Content hashes are derived only from fixed, checked-in public
        // artifacts in the private checkout, never from request input.
        // HTML remains no-store; URLs uniquely identify the current build.
        $this->engine->addGlobal('admin_assets', self::adminAssetUrls());
    }

    /**
     * Static URLs are cacheable; authenticated HTML and API responses are not.
     * Keep URL versions deterministic across requests and deployments.
     *
     * @return array{app_css:string,app_js:string,theme_init:string,icons:string}
     */
    private static function adminAssetUrls(): array
    {
        $root = dirname(__DIR__, 2) . '/public/assets/admin/';
        $names = [
            'app_css' => 'app.css',
            'app_js' => 'app.js',
            'theme_init' => 'theme-init.js',
            'icons' => 'icons.svg',
        ];
        $urls = [];
        foreach ($names as $key => $filename) {
            $path = $root . $filename;
            // Missing files are checked by the production asset availability check;
            // the renderer remains constructible for isolated tests.
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            $urls[$key] = '/assets/admin/' . $filename
                . (is_string($hash) ? '?v=' . substr($hash, 0, 16) : '');
        }

        return $urls;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data): string
    {
        if (preg_match('~^(?:admin|account|layouts|components)/[a-z0-9/_-]+\\.html\\.twig$~D', $template) !== 1) {
            throw new RuntimeException('Template name was not allowlisted.');
        }

        return $this->engine->render($template, $data);
    }
}
