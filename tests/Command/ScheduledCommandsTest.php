<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\Market\Ticker\HistoryPruner;
use App\Command\PruneHistoryCommand;
use App\Schedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * Every command line the schedule dispatches must parse against the command it names.
 *
 * The schedule is a string and the command is a definition, and nothing connected the two: the nightly
 * prune carried `--days=30` for five days after the command had renamed the option to `--years`, so the
 * scheduler failed it every night and the history tables grew unbounded. The scheduler runs in its own
 * container and its failures surface in nobody's terminal — this is the place they surface.
 */
#[AllowMockObjectsWithoutExpectations]
class ScheduledCommandsTest extends TestCase
{
    /** @return array<string, Command> The schedulable commands, by name, built with stubbed dependencies. */
    private function commands(): array
    {
        $application = new Application();
        $prune = new PruneHistoryCommand(new HistoryPruner($this->createMock(EntityManagerInterface::class)));
        $application->addCommand($prune);
        // The application adds the `command` argument the first token of the line binds to.
        $prune->mergeApplicationDefinition();

        return [(string) $prune->getName() => $prune];
    }

    /** @return list<RunCommandMessage> */
    private function scheduledCommandMessages(): array
    {
        $schedule = (new Schedule(new ArrayAdapter()))->getSchedule();
        $messages = [];

        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof RunCommandMessage) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }

    public function testEveryScheduledCommandLineBindsToItsCommandDefinition(): void
    {
        $commands = $this->commands();
        $messages = $this->scheduledCommandMessages();
        $this->assertNotEmpty($messages, 'The schedule dispatches at least the nightly prune.');

        foreach ($messages as $message) {
            $input = new StringInput($message->input);
            $name = $input->getFirstArgument();
            $this->assertArrayHasKey($name, $commands, "Scheduled command '$name' is not a command this test knows how to build.");

            // bind() throws on an option the definition does not declare, validate() on a missing argument.
            $input->bind($commands[$name]->getDefinition());
            $input->validate();
        }
    }

    public function testNightlyPruneKeepsTheDefaultRetentionInSimulatedYears(): void
    {
        $prune = $this->commands()['app:prune-history'];
        $lines = array_map(static fn (RunCommandMessage $m): string => $m->input, $this->scheduledCommandMessages());
        $pruneLines = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'app:prune-history')));
        $this->assertCount(1, $pruneLines);

        $input = new StringInput($pruneLines[0]);
        $input->bind($prune->getDefinition());

        $this->assertEqualsWithDelta(HistoryPruner::DEFAULT_YEARS_KEPT, (float) $input->getOption('years'), 1e-9);
        $this->assertSame(HistoryPruner::THINNED_ROWS_PER_YEAR, (int) $input->getOption('per-year'));
    }
}
