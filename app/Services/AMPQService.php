<?php

namespace App\Services;

use App\Notifications\FileDeletedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class AMPQService
{
    private AMQPStreamConnection $connection;

    private int $processed = 0;

    public function __construct()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                config('services.rabbitmq.host'),
                config('services.rabbitmq.port'),
                config('services.rabbitmq.user'),
                config('services.rabbitmq.password'),
            );
        } catch (\Exception $e) {
            Log::error('Error establishing AMPQ stream: '. $e->getMessage());
        }
    }

    /**
     * Publish a file-deletion event, picked up by the rabbitmq-consumer
     * command (M5) to send the deletion-notification email.
     */
    public function publishFileDeletion(array $payload): void
    {
        $queue = config('services.rabbitmq.file_deletions_queue');

        $channel = $this->connection->channel();
        $channel->queue_declare($queue, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($payload),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        // Publish straight to the queue via RabbitMQ's default (nameless)
        // exchange — routing_key = queue name. No custom exchange needed
        // for a single producer/single queue setup like this one.
        $channel->basic_publish($message, '', $queue);

        $channel->close();
        try {
            $this->connection->close();
        } catch (\Exception $e) {
            Log::error('Error closing AMPQ connection: '.$e->getMessage());
        }
    }

    public function queryConsume(string $queue, string $recipient, ?int $limit = null): void
    {
        $channel = $this->connection->channel();
        $channel->queue_declare($queue, false, true, false, false);

        Log::channel('consumer_stdout')->info("Listening on [{$queue}]...");

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
        try {
            $this->connection->close();
        } catch (\Exception $e) {
            Log::error('Error closing AMPQ connection: '.$e->getMessage());
        }
    }

    private function handleMessage(AMQPMessage $message, string $recipient): void
    {
        try {
            $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

            Notification::route('mail', $recipient)->notify(new FileDeletedNotification($payload));

            Log::channel('consumer_stdout')->info("Notified {$recipient} about deletion of file #{$payload['file_id']}.");

        } catch (Throwable $e) {
            Log::channel('consumer_stdout')->error('Failed to process file-deletion message: '.$e->getMessage(), [
                'body' => $message->getBody(),
            ]);
        } finally {
            // No dead-letter queue configured — always ack rather than
            // requeue, so a malformed message can't loop forever.
            $message->ack();
        }
    }
}
