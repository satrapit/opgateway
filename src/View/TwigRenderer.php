<?php

declare(strict_types=1);

namespace OPGateway\View;

use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Private, server-side Twig renderer. No templates or vendor files are public.
 *
 * Escaping and strict variable handling are mandatory. Controllers and
 * adapters must authorize and prepare safe view data before calling render.
 */
final class TwigRenderer
{
    private static ?Environment $environment = null;

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data): string
    {
        return self::environment()->render($template, $data);
    }

    private static function environment(): Environment
    {
        if (self::$environment !== null) {
            return self::$environment;
        }

        $root = dirname(__DIR__, 2);
        if (!class_exists(Environment::class)) {
            $autoload = $root . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                throw new RuntimeException('Twig runtime dependency is unavailable.');
            }
            require_once $autoload;
        }

        return self::$environment = new Environment(
            new FilesystemLoader($root . '/templates'),
            [
                'autoescape' => 'html',
                'strict_variables' => true,
                'cache' => false,
                'debug' => false,
            ],
        );
    }
}
