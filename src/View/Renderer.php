<?php

declare(strict_types=1);

namespace WatchRss\View;

use RuntimeException;

/**
 * Template-Renderer für schlichte PHP-Templates.
 *
 * Templates liegen in /templates und sehen die übergebenen Variablen direkt,
 * ausserdem $this (= Renderer) für Helfer wie e(), url() oder partial().
 * Damit bleibt die Darstellung vollständig aus src/ heraus.
 */
final class Renderer
{
    /** @var array<string,mixed> Variablen, die jedes Template bekommt. */
    private array $shared = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * @param array<string,mixed> $values
     */
    public function share(array $values): void
    {
        $this->shared = $values + $this->shared;
    }

    /**
     * Rendert ein Template in das Layout.
     *
     * @param array<string,mixed> $data
     */
    public function page(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);

        return $this->render($layout, $data + ['content' => $content]);
    }

    /**
     * Rendert ein Template ohne Layout.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template nicht gefunden: ' . $template);
        }

        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Teil-Template (z. B. eine Kanalzeile).
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->render('partials/' . $template, $data);
    }

    /** HTML-Escaping - in Templates kurz als $this->e(). */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** JSON für Inline-Skripte (XSS-sicher eingebettet). */
    public function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * Absolute URL innerhalb der Installation.
     */
    public function url(string $path = '/'): string
    {
        $base = rtrim((string) ($this->shared['baseUrl'] ?? ''), '/');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * URL zu einer statischen Datei inklusive Cache-Buster (Dateidatum).
     */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = $this->templateDir . '/../www/' . $path;
        $stamp = is_file($file) ? (string) filemtime($file) : '1';

        return $this->url($path) . '?v=' . $stamp;
    }

    /**
     * Maße einer Bilddatei aus dem Webroot.
     *
     * Open Graph will Breite und Höhe mitgeliefert bekommen (die Vorschau
     * kann dann Platz reservieren, bevor das Bild geladen ist). Die Werte
     * hier auszulesen statt im Template zu pflegen heißt: Wird das Bild
     * ausgetauscht, stimmen die Angaben automatisch weiter.
     *
     * @return array{0:int,1:int} Breite und Höhe, [0,0] wenn unlesbar
     */
    public function imageSize(string $path): array
    {
        $file = $this->templateDir . '/../www/' . ltrim($path, '/');
        if (!is_file($file)) {
            return [0, 0];
        }

        $size = @getimagesize($file);

        return $size === false ? [0, 0] : [(int) $size[0], (int) $size[1]];
    }

    /**
     * Datum in Anzeige-Zeitzone formatieren (gespeichert wird UTC).
     */
    public function date(?string $utc, string $format = 'd.m.Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '–';
        }

        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone((string) APP_TIMEZONE))
                ->format($format);
        } catch (\Exception) {
            return '–';
        }
    }
}
