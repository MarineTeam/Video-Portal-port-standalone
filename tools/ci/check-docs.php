<?php

declare(strict_types=1);

// Fails when docs/SERVICES.md has fallen behind the providers.
//
// The tables in that document are the providers' own label(), id() and
// limits(), and a reader is entitled to assume they say what the code says.
// A provider added without a row, or a limits() sentence rewritten and not
// carried across, is caught here rather than by somebody choosing a service
// on the strength of a paragraph that stopped being true.

$doc = @file_get_contents(__DIR__ . '/../../SERVICES.md');
if (!is_string($doc)) {
    fwrite(STDERR, "SERVICES.md is missing.\n");
    exit(1);
}

// The provider classes themselves, not a booted app: this runs in the lint
// job, which has no database.
require_once __DIR__ . '/../../app/autoload.php';
$failures = [];
$checked = 0;
foreach (glob(__DIR__ . '/../../app/Services/*/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/^(final )?class (\w+)/m', $source, $m) !== 1) {
        continue;
    }
    $slot = basename(dirname($file));
    $class = "App\\Services\\$slot\\{$m[2]}";
    if (!class_exists($class) || !is_subclass_of($class, \App\Services\ServiceProvider::class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }
    $checked++;
    $id = $class::id();
    $label = $class::label();
    if (!str_contains($doc, "| **$label** | `$id` |")) {
        $failures[] = "$slot/$id: no row for “{$label}”";
        continue;
    }
    $limits = str_replace('|', '\|', $class::limits());
    if ($limits !== '' && !str_contains($doc, $limits)) {
        $failures[] = "$slot/$id: the row does not carry the current limits() sentence";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "SERVICES.md is out of date:\n  " . implode("\n  ", $failures) . "\n");
    fwrite(STDERR, "\nRegenerate the tables from the providers and commit the result.\n");
    exit(1);
}
echo "SERVICES.md covers all $checked providers.\n";
