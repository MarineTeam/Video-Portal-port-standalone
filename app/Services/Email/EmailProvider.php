<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\ServiceProvider;

interface EmailProvider extends ServiceProvider
{
    public function send(Message $message, string $from): SendResult;
}
