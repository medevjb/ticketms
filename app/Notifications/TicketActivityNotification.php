<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketActivityNotification extends Notification
{
    /**
     * @param  array{event: string, ticket_id: int, ticket_number: string, activity_id: int, summary: string}  $payload
     */
    public function __construct(array $payload, string $notificationId)
    {
        $this->id = $notificationId;
        $this->payload = $payload;
    }

    /** @var array{event: string, ticket_id: int, ticket_number: string, activity_id: int, summary: string} */
    private array $payload;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = $notifiable instanceof User && in_array('customer', $notifiable->tyroRoleSlugs(), true)
            ? route('customer.tickets.show', $this->payload['ticket_id'])
            : route('dashboard.tickets.show', $this->payload['ticket_id']);

        return (new MailMessage)
            ->subject($this->title().' · '.$this->payload['ticket_number'])
            ->greeting('Ticket update')
            ->line($this->payload['summary'])
            ->action('View ticket', $ticketUrl)
            ->salutation('Ticketing support');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return ['schema_version' => 1, ...$this->payload];
    }

    private function title(): string
    {
        return match ($this->payload['event']) {
            'ticket.created' => 'Ticket created',
            'ticket.assigned', 'ticket.reassigned' => 'Ticket assignment updated',
            'ticket.message_posted' => 'New ticket reply',
            'ticket.sla_approaching' => 'Ticket SLA approaching',
            'ticket.sla_breached' => 'Ticket SLA breached',
            default => 'Ticket status updated',
        };
    }
}
