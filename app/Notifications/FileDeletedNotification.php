<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileDeletedNotification extends Notification
{
    use Queueable;

    private array $fileData;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Файл был удален: ' . $this->fileData['original_name'])
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line('Обратите внимание, что файл **' . $this->fileData['original_name'] . '** был безвозвратно удален из вашего хранилища.')
            ->line('Размер файла: ' . $this->fileData['size_bytes'] / 1024)
            ->line('Дата удаления: ' . now()->toDateTimeString())
            ->action('Перейти в хранилище', url('/storage'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
