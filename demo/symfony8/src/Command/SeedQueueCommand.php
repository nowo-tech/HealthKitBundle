<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\DemoJob;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Empties the demo `async` queue and enqueues N jobs (no worker consumes them).
 */
#[AsCommand(name: 'app:demo:seed-queue', description: 'Reset the demo async queue to N waiting messages')]
final readonly class SeedQueueCommand
{
    public function __construct(
        private MessageBusInterface $bus,
        private Connection $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Messages to enqueue')] int $count = 12): int
    {
        if ($this->connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'default'");
        }
        for ($i = 1; $i <= $count; ++$i) {
            $this->bus->dispatch(new DemoJob($i));
        }
        $io->success(sprintf('%d demo messages waiting on the async transport.', $count));

        return 0;
    }
}
