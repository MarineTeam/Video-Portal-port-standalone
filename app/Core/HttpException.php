<?php

declare(strict_types=1);

namespace App\Core;

/** An outbound request that failed; the message is fit to show an admin. */
final class HttpException extends \RuntimeException
{
}
