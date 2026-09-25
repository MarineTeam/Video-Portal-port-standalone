<?php

declare(strict_types=1);

// Every <?= in a template must escape: e(...), or the explicit $v->raw(...),
// whose every use is listed so a reviewer sees them. Anything else fails.

$dirs = ['app/Templates', ...glob('themes/*/templates') ?: [], ...glob('plugins/*/templates') ?: []];
$failures = [];
$raw = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) ?: [] as $n => $line) {
            if (!preg_match_all('/<\?=\s*(.*?)\s*\?>/', $line, $m)) {
                continue;
            }
            foreach ($m[1] as $expr) {
                $where = $file->getPathname() . ':' . ($n + 1);
                if (str_starts_with($expr, 'e(')) {
                    continue;
                }
                // A partial is a rendered template, escaped by its own rules.
                if (str_starts_with($expr, '$v->partial(')) {
                    continue;
                }
                if (str_starts_with($expr, '$v->raw(')) {
                    $raw[] = "$where  $expr";
                    continue;
                }
                // Ternaries that choose between two literal strings are safe.
                // Only the two branches reach the page, whatever the condition is.
                if (preg_match('/\?\s*\'[^\'<>"]*\'\s*:\s*\'[^\'<>"]*\'$/', $expr) && !str_contains($expr, '<?') || preg_match('/^\'[^\'<>"]*\'$/', $expr)) {
                    continue;
                }
                $failures[] = "$where  $expr";
            }
        }
    }
}
echo "Unescaped output by design (\$v->raw):\n  " . implode("\n  ", $raw) . "\n";
if ($failures !== []) {
    fwrite(STDERR, "Unescaped template output:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "Every other template output is escaped.\n";
