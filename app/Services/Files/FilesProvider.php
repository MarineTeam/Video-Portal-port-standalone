<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceProvider;

/**
 * Where uploaded files' bytes live. The app route /api/files/[id]/content
 * decides who may see a file; a provider only answers "here are the bytes"
 * (or a short-lived signed redirect to them).
 */
interface FilesProvider extends ServiceProvider
{
    /** Stores a finished upload at $object (e.g. files/<id>.pdf), streaming it. */
    public function put(string $localPath, string $object): void;

    public function delete(string $object): void;

    public function exists(string $object): bool;

    /**
     * Answers a GET for the object: Range, conditional requests and the
     * disposition are the caller's to decide and are passed in $headers.
     *
     * @param array<string, string> $headers Content-Type, Content-Disposition, Cache-Control…
     */
    public function serve(string $object, Request $request, array $headers): Response;

    /**
     * Opens the object for reading (a copy, an export, a probe).
     *
     * @return resource|null
     */
    public function open(string $object): mixed;
}
