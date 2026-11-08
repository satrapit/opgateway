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
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data): string
    {
        if (preg_match('~^(?:admin|layouts|components)/[a-z0-9/_-]+\\.html\\.twig$~D', $template) !== 1) {
            throw new RuntimeException('Template name was not allowlisted.');
        }

        return $this->engine->render($template, $data);
    }
}
