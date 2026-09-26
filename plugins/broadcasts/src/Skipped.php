<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Broadcasts;

/**
 * There was no way to reach them on this channel — and nothing was wrong.
 *
 * Distinct from a failure because a site with no email provider configured
 * would otherwise show four hundred red rows, each blaming an address that
 * is perfectly good.
 */
final class Skipped extends \RuntimeException
{
}
