<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Render views/$view.php inside views/$layout.php (null layout = bare partial). */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture(self::path($view), $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture(self::path($layout), $data + ['content' => $content]);
    }

    public static function partial(string $view, array $data = []): string
    {
        return self::capture(self::path($view), $data);
    }

    private static function path(string $view): string
    {
        return dirname(__DIR__, 2) . '/views/' . $view . '.php';
    }

    private static function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
