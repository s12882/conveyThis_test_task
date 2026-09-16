<?php

namespace App\Console\Commands;

use App\Notifications\FileDeletedNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

#[Signature('rabbitmq:consume-file-deletions {--limit= : Stop after processing this many messages (mainly for testing); omit to run forever}')]
#[Description('Long-running consumer: sends the deletion-notification email for every file-deletion event published to RabbitMQ.')]
class ConsumeFileDeletions extends Command
{
    private int $processed = 0;

    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $queue = config('services.rabbitmq.file_deletions_queue');
        $recipient = config('files.notification_email');

        if (! $recipient) {
            $this->error('files.notification_email (FILE_DELETION_NOTIFICATION_EMAIL) is not set.');

            return self::FAILURE;
        }

        $connection = new AMQPStreamConnection(
            config('services.rabbitmq.host'),
            config('services.rabbitmq.port'),
            config('services.rabbitmq.user'),
            config('services.rabbitmq.password'),
        );
        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);

        $this->info("Listening on [{$queue}]...");

        $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($recipient) {
                $this->handleMessage($message, $recipient);
                $this->processed++;
            },
        );

        while ($channel->is_consuming()) {
            try {
                // In test mode, stop waiting after a few quiet
                // seconds rather than blocking forever if fewer messages
                // than --limit are actually available.
                $channel->wait(timeout: $limit !== null ? 3 : 0);
            } catch (AMQPTimeoutException) {
                break;
            }

            if ($limit !== null && $this->processed >= $limit) {
                break;
            }
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function handleMessage(AMQPMessage $message, string $recipient): void
    {
        try {
            $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

            Notification::route('mail', $recipient)->notify(new FileDeletedNotification($payload));

            $this->info("Notified {$recipient} about deletion of file #{$payload['file_id']}.");
        } catch (Throwable $e) {
            Log::error('Failed to process file-deletion message: '.$e->getMessage(), [
                'body' => $message->getBody(),
            ]);
        } finally {
            // No dead-letter queue configured — always ack rather than
            // requeue, so a malformed message can't loop forever.
            $message->ack();
        }
    }
}
