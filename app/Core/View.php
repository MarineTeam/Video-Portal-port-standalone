<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain PHP templates, resolved active theme → parent theme → core.
 *
 * Inside a template: $v is this View, and the helpers e(), url(), t() are in
 * scope. Output is escaped by writing <?= e($x) ?>; the only unescaped path is
 * $v->raw($html), which CI greps for so every use is a decision somebody made.
 *
 * A child theme overriding one partial only needs that one file: anything it
 * lacks falls through to its parent, then to app/Templates.
 */
final class View
{
    /** @var list<string> directories searched in order */
    private array $paths;

    private string $nonce;

    /** @var array<string, string> named sections filled by a template for its layout */
    private array $sections = [];

    private ?string $sectionName = null;

    /** @var array<string, mixed> shared with every template */
    private array $shared = [];

    public function __construct(string $corePath, private readonly ?Hooks $hooks = null)
    {
        $this->paths = [rtrim($corePath, '/')];
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** Puts a theme's templates in front of core's (call parent first, then child). */
    public function prependPath(string $dir): void
    {
        array_unshift($this->paths, rtrim($dir, '/'));
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** The per-response nonce for the inline scripts and the branding style. */
    public function nonce(): string
    {
        return $this->nonce;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    public function resolve(string $name): ?string
    {
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $name)) {
            throw new \InvalidArgumentException("Bad template name: $name");
        }
        if ($this->hooks !== null) {
            $override = $this->hooks->apply('template.resolve', null, $name);
            if (is_string($override) && is_file($override)) {
                return $override;
            }
        }
        foreach ($this->paths as $dir) {
            $file = "$dir/$name.php";
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $name, array $vars = []): string
    {
        $file = $this->resolve($name);
        if ($file === null) {
            throw new \RuntimeException("Template not found: $name");
        }
        if ($this->hooks !== null) {
            $vars = (array) $this->hooks->apply('template.' . str_replace('/', '.', $name) . '.vars', $vars);
        }
        return $this->include($file, $this->shared + $vars);
    }

    /**
     * Renders $name inside a layout. The template's output becomes the
     * layout's $content, and any sections it opened are available too.
     *
     * @param array<string, mixed> $vars
     */
    public function page(string $name, array $vars = [], string $layout = 'layouts/site'): string
    {
        $content = $this->render($name, $vars);
        return $this->render($layout, $vars + ['content' => $content, 'sections' => $this->sections]);
    }

    public function partial(string $name, array $vars = []): string
    {
        return $this->render($name, $vars);
    }

    public function start(string $section): void
    {
        $this->sectionName = $section;
        ob_start();
    }

    public function end(): void
    {
        if ($this->sectionName === null) {
            throw new \LogicException('end() without start()');
        }
        $this->sections[$this->sectionName] = ($this->sections[$this->sectionName] ?? '') . (string) ob_get_clean();
        $this->sectionName = null;
    }

    public function section(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    /** Deliberately unescaped output. Every call is a decision; CI lists them. */
    public function raw(string $html): string
    {
        return $html;
    }

    public static function escape(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        return htmlspecialchars(is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** JSON for a <script type="application/ld+json"> or a data-* attribute. */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $__vars */
    private function include(string $__file, array $__vars): string
    {
        $v = $this;
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
