<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Actions and filters, the extension surface plugins and themes are written
 * against.
 *
 *   on($name, $fn, $priority)       an action; do($name, ...$args) calls each
 *   filter($name, $fn, $priority)   a filter; apply($name, $value, ...$args)
 *                                   threads $value through each and returns it
 *
 * Lower priority runs first; equal priorities run in registration order.
 *
 * A callback that throws is contained: the dispatcher logs it, skips that
 * callback's contribution (an action continues with the next; a filter keeps
 * the value it had) and reports the failure against the plugin that
 * registered it, so a broken plugin degrades one feature rather than the page.
 */
final class Hooks
{
    /** @var array<string, list<array{fn: callable, priority: int, seq: int, owner: ?string}>> */
    private array $actions = [];

    /** @var array<string, list<array{fn: callable, priority: int, seq: int, owner: ?string}>> */
    private array $filters = [];

    private int $seq = 0;

    /** The plugin or theme currently registering callbacks, if any. */
    private ?string $owner = null;

    /** @var (callable(string $owner, \Throwable $e, string $hook): void)|null */
    private $onFailure = null;

    public function on(string $name, callable $fn, int $priority = 10): void
    {
        $this->actions[$name][] = ['fn' => $fn, 'priority' => $priority, 'seq' => $this->seq++, 'owner' => $this->owner];
    }

    public function filter(string $name, callable $fn, int $priority = 10): void
    {
        $this->filters[$name][] = ['fn' => $fn, 'priority' => $priority, 'seq' => $this->seq++, 'owner' => $this->owner];
    }

    public function do(string $name, mixed ...$args): void
    {
        foreach ($this->ordered($this->actions[$name] ?? []) as $entry) {
            try {
                ($entry['fn'])(...$args);
            } catch (\Throwable $e) {
                $this->failed($entry, $e, $name);
            }
        }
    }

    public function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->ordered($this->filters[$name] ?? []) as $entry) {
            try {
                $value = ($entry['fn'])($value, ...$args);
            } catch (\Throwable $e) {
                $this->failed($entry, $e, $name);
            }
        }
        return $value;
    }

    public function has(string $name): bool
    {
        return !empty($this->actions[$name]) || !empty($this->filters[$name]);
    }

    /**
     * Runs $fn with every callback it registers attributed to $owner.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function as(string $owner, callable $fn): mixed
    {
        $saved = $this->owner;
        $this->owner = $owner;
        try {
            return $fn();
        } finally {
            $this->owner = $saved;
        }
    }

    /** Drops every callback a plugin registered, when it is deactivated mid-request. */
    public function forget(string $owner): void
    {
        foreach ([&$this->actions, &$this->filters] as &$table) {
            foreach ($table as $name => $entries) {
                $table[$name] = array_values(array_filter($entries, fn ($e) => $e['owner'] !== $owner));
            }
        }
    }

    /** @param callable(string, \Throwable, string): void $handler */
    public function onFailure(callable $handler): void
    {
        $this->onFailure = $handler;
    }

    /**
     * @param list<array{fn: callable, priority: int, seq: int, owner: ?string}> $entries
     * @return list<array{fn: callable, priority: int, seq: int, owner: ?string}>
     */
    private function ordered(array $entries): array
    {
        usort($entries, fn ($a, $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
        return $entries;
    }

    /** @param array{fn: callable, priority: int, seq: int, owner: ?string} $entry */
    private function failed(array $entry, \Throwable $e, string $hook): void
    {
        Log::error("Hook callback failed on $hook: " . $e->getMessage(), [
            'plugin' => $entry['owner'],
            'exception' => $e::class,
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
        if ($entry['owner'] !== null && $this->onFailure !== null) {
            try {
                ($this->onFailure)($entry['owner'], $e, $hook);
            } catch (\Throwable) {
                // Reporting a failure must never become a second one.
            }
        }
        // Without an owner the callback is core's own: that is a bug, not a
        // plugin to contain, so it is logged above and otherwise surfaced.
        if ($entry['owner'] === null) {
            throw $e;
        }
    }
}
