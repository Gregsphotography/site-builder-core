<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\SetupFailure;
use SiteBuilder\Core\Installation\SetupManager;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    protected $signature = 'site-builder:doctor {--answers= : Application-relative v1 answers JSON} {--profile= : Match the selected profile} {--json : Emit a versioned report}';

    protected $description = 'Inspect Site Builder readiness without writing files, caches, schema or content';

    public function handle(SetupManager $manager): int
    {
        try {
            $answers = $this->option('answers') !== null ? app(SetupCommand::class)->readAnswers($this->option('answers')) : $manager->installedAnswers($this->option('profile'));
            if ($this->option('profile') !== null && $answers->get('profile') !== $this->option('profile')) {
                throw new SetupFailure('profile-conflict', 'The CLI and answers profiles disagree.');
            }
            $report = $manager->doctor($answers);
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('Site Builder — '.$report['status']);
                foreach ($report['checks'] as $check) {
                    $this->line($check->status.' '.$check->id.': '.$check->message.($check->action === null ? '' : ' '.$check->action));
                }
            }

            return match ($report['status']) {
                'ready' => 0, 'unavailable' => 2, default => 1
            };
        } catch (SetupFailure $failure) {
            $this->writeError($failure->reason.': '.$failure->getMessage());

            return $failure->exitStatus;
        } catch (\Throwable) {
            $this->writeError('doctor-unavailable: A capability could not be inspected. No connection or exception details are disclosed.');

            return 2;
        }
    }

    private function writeError(string $message): void
    {
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
