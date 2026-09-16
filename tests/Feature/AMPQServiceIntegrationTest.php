<?php

namespace Tests\Feature;

use App\Services\AMPQService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

/**
 * Exercises the real RabbitMQ broker (the `rabbitmq` Docker service) rather
 * than a mock, mirroring VirusScanServiceIntegrationTest's approach for
 * clamd. Requires RabbitMQ to be reachable, unlike the rest of the suite.
 */
class AMPQServiceIntegrationTest extends TestCase
{
    public function test_publishing_a_file_deletion_lands_the_expected_payload_on_the_queue(): void
    {
        $queue = config('services.rabbitmq.file_deletions_queue');

        // Start from an empty queue so this test doesn't pick up a stray
        // message left over from a previous run.
        $this->purgeQueue($queue);

        $payload = [
            'file_id' => 123,
            'original_name' => 'integration-test.pdf',
            'size_bytes' => 4567,
            'deletion_reason' => 'manual',
            'deleted_at' => now()->toIso8601String(),
        ];

        (new AMPQService)->publishFileDeletion($payload);

        $connection = new AMQPStreamConnection(
            config('services.rabbitmq.host'),
            config('services.rabbitmq.port'),
            config('services.rabbitmq.user'),
            config('services.rabbitmq.password'),
        );
        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);

        $message = $channel->basic_get($queue, true);

        $channel->close();
        $connection->close();

        $this->assertNotNull($message, 'Expected a message to be waiting on the file_deletions queue.');
        $this->assertSame($payload, json_decode($message->getBody(), true));
    }

    private function purgeQueue(string $queue): void
    {
        $connection = new AMQPStreamConnection(
            config('services.rabbitmq.host'),
            config('services.rabbitmq.port'),
            config('services.rabbitmq.user'),
            config('services.rabbitmq.password'),
        );
        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_purge($queue);
        $channel->close();
        $connection->close();
    }
}
