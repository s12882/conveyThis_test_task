<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AMPQService
{
    private AMQPStreamConnection $connection;

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
}
