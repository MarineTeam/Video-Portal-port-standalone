<?php

declare(strict_types=1);

namespace App\Services\Video;

/** Captions kept at the provider (Bunny, Vimeo): the provider is the source of truth. */
interface CaptionOps
{
    /** @return list<array{srclang: string, label: string}> */
    public function list(): array;

    public function add(string $srclang, string $label, string $vtt): void;

    public function delete(string $srclang): void;
}
