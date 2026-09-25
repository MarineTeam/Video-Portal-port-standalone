<?php

declare(strict_types=1);

// Fails when shipped code calls a process function. Many hosts disable them,
// and the code must not care — so it never uses them at all.

$banned = ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru', 'pcntl_exec'];
$roots = ['app', 'install', 'bin', 'plugins', 'themes', 'public'];
$failures = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' && $file->getExtension() !== 'phtml') {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        foreach ($tokens as $i => $token) {
            if ($token === '`') {
                $failures[] = $file->getPathname() . ': backtick operator';
                continue;
            }
            if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), $banned, true)) {
                continue;
            }
            // A call, not a method or a word in a string: next meaningful token is "(",
            // previous is not -> or :: or "function".
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $k = $i - 1;
            while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k--;
            }
            $prev = $tokens[$k] ?? null;
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true);
            if (($tokens[$j] ?? null) === '(' && !$isMember) {
                $failures[] = $file->getPathname() . ':' . $token[2] . ' calls ' . $token[1] . '()';
            }
        }
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Banned process functions:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "No banned process functions.\n";
