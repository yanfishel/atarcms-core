<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Kernel\Clock;

/**
 * The inbox of the site's forms: the admin's Messages screen marks messages read or unread and deletes them (a
 * deletion is audited, named by its sender). Visitors' forms arrive through Form\FormService.
 */
final readonly class MessageService
{
    public const string PERMISSION = 'messages.manage';

    public function __construct(
        private MessageRepository $messages,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    public function markRead(Message $message): void
    {
        if ($message->readAt === null) {
            $this->messages->setRead($message->id, $this->clock->now());
        }
    }

    public function markUnread(Message $message): void
    {
        $this->messages->setRead($message->id, null);
    }

    public function delete(Message $message, ?User $actor, ?string $ip): void
    {
        $this->messages->delete($message->id);
        $this->audit->record('message.deleted', $actor?->id, 'message', $message->id, ['form' => $message->form, 'name' => $message->sender(), 'email' => $message->email], $ip);
    }
}
