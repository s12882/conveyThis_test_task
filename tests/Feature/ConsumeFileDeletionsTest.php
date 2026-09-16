<?php

namespace Tests\Feature;

use App\Notifications\FileDeletedNotification;
use App\Services\AMPQService;
use Illuminate\Support\Facades\Notification;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

/**
 * Exercises the real RabbitMQ broker (mirrors AMPQServiceIntegrationTest
 * and VirusScanServiceIntegrationTest's approach for their own real infra).
 */
class ConsumeFileDeletionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeQueue(config('services.rabbitmq.file_deletions_queue'));
    }

    public function test_it_sends_a_notification_for_a_published_deletion_event(): void
    {
        Notification::fake();

        $payload = [
            'file_id' => 321,
            'original_name' => 'consumer-command-test.pdf',
            'size_bytes' => 2048,
            'deletion_reason' => 'manual',
            'deleted_at' => now()->toIso8601String(),
        ];

        (new AMPQService)->publishFileDeletion($payload);

        $this->artisan('rabbitmq:consume-file-deletions', ['--limit' => 1])
            ->assertSuccessful();

        Notification::assertSentOnDemand(
            FileDeletedNotification::class,
            function (FileDeletedNotification $notification, array $channels, object $notifiable) {
                return $notifiable->routes['mail'] === config('files.notification_email');
            }
        );
    }

    public function test_it_exits_gracefully_when_no_messages_are_waiting(): void
    {
        Notification::fake();

        $this->artisan('rabbitmq:consume-file-deletions', ['--limit' => 1])
            ->assertSuccessful();

        Notification::assertNothingSent();
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
