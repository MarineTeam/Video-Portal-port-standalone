<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Db;
use App\Core\Log;
use App\Services\Registry;

/**
 * Everything that sends email goes through here: notifications, broadcasts,
 * resets, magic links, verification, the admin refusal alert. Every send
 * writes an email_log row, because on shared hosting that log is the only
 * way to learn a message never left.
 */
final class Mailer
{
    public function __construct(private readonly Db $db, private readonly Registry $services, private readonly string $defaultFrom)
    {
    }

    public function isConfigured(): bool
    {
        return !in_array($this->services->activeId('email'), [null, 'none'], true);
    }

    public function send(Message $message): SendResult
    {
        $provider = $this->services->active('email');
        $id = $provider === null ? 'none' : $provider::id();
        try {
            $result = $provider instanceof EmailProvider
                ? $provider->send($message, $this->defaultFrom)
                : SendResult::skipped('Email is not set up.');
        } catch (\Throwable $e) {
            Log::error('Email provider threw: ' . $e->getMessage(), ['provider' => $id]);
            $result = SendResult::failed('The email provider failed unexpectedly.');
        }
        try {
            $this->db->insert('email_log', [
                'to_address' => $message->to,
                'subject' => mb_substr($message->subject, 0, 500),
                'provider' => $id,
                'status' => $result->status,
                'provider_message_id' => $result->messageId,
                'error' => $result->error === null ? null : mb_substr(Log::mask($result->error), 0, 2000),
                'text_body' => $message->sensitive ? null : $message->text,
                'html_body' => $message->sensitive ? null : $message->html,
                'reply_to' => $message->replyTo,
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not write the email log: ' . $e->getMessage());
        }
        return $result;
    }
}
