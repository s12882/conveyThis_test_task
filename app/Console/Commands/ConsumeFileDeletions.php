<?php

namespace App\Console\Commands;

use App\Services\AMPQService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rabbitmq:consume-file-deletions {--limit= : Stop after processing this many messages (mainly for testing); omit to run forever}')]
#[Description('Long-running consumer: sends the deletion-notification email for every file-deletion event published to RabbitMQ.')]
class ConsumeFileDeletions extends Command
{
    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $queue = config('services.rabbitmq.file_deletions_queue');
        $recipient = config('files.notification_email');

        if (! $recipient) {
            $this->error('files.notification_email (FILE_DELETION_NOTIFICATION_EMAIL) is not set.');

            return self::FAILURE;
        }

        app(AMPQService::class)->queryConsume($queue, $recipient, $limit);

        return self::SUCCESS;
    }
}
