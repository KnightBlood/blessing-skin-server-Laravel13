<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SiteMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public $title;

    public $content;

    public ?int $communicationId = null;

    public ?string $deliveryToken = null;

    public function __construct(string $title, $content = '', ?int $communicationId = null, ?string $deliveryToken = null)
    {
        $this->title = $title;
        $this->content = $content;
        $this->communicationId = $communicationId;
        $this->deliveryToken = $deliveryToken;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array
     */
    public function toArray($notifiable)
    {
        return ($this->communicationId ? ['communication_id' => $this->communicationId, 'delivery_token'=>$this->deliveryToken, 'format' => 'html'] : []) + [
            'title' => $this->title,
            'content' => $this->content,
        ];
    }
}
