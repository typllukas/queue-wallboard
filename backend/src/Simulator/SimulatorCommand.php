<?php

declare(strict_types=1);

namespace QueueWallboard\Simulator;

use Override;
use Random\Engine\Mt19937;
use Random\Randomizer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'simulator:run', description: 'Run the AMI simulator and its scenario control server')]
final class SimulatorCommand extends Command
{
    private const string AMI_ADDRESS = '0.0.0.0:5038';
    /** arbitrary, the port after AMI */
    private const string CONTROL_ADDRESS = '0.0.0.0:5039';
    /** outlasts several reconnect attempts of the daemon, so the outage is visible in the demo */
    private const float EXCHANGE_OUTAGE_SECONDS = 6.0;

    #[Override]
    protected function configure(): void
    {
        $this->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed of the random generator (default: random)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seedOption = $input->getOption('seed');
        $seed = $seedOption === null ? random_int(0, PHP_INT_MAX) : filter_var($seedOption, FILTER_VALIDATE_INT);
        if ($seed === false) {
            $io->error('The seed must be an integer.');

            return Command::INVALID;
        }

        $callCentre = new CallCentre(
            Floor::createDemoFloor(),
            SimulationTimings::createDemoTimings(),
            Loop::get(),
            new Randomizer(new Mt19937($seed)),
            new NativeClock(),
        );
        $callCentre->start(Scenario::Normal);
        $amiServer = new AmiServer(new SocketServer(self::AMI_ADDRESS), $callCentre, $this->readEnvironment('AMI_USERNAME'), $this->readEnvironment('AMI_SECRET'));
        new ControlServer(new SocketServer(self::CONTROL_ADDRESS), $callCentre, $amiServer, Loop::get(), self::EXCHANGE_OUTAGE_SECONDS, explode(',', $this->readEnvironment('WEB_ORIGINS')));
        $io->writeln(sprintf(
            'Simulator started with seed %d: AMI on %s, control on %s, scenario %s.',
            $seed,
            self::AMI_ADDRESS,
            self::CONTROL_ADDRESS,
            $callCentre->getScenario()->value,
        ));
        Loop::run();

        return Command::SUCCESS;
    }

    private function readEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Environment variable %s is not set.', $name));
        }

        return $value;
    }
}
