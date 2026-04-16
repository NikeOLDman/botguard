<?php

declare(strict_types=1);

namespace App\Command;

use App\BotGuard\BotGuardLogCleaner;
use App\Entity\BotGuard\BotGuardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BotGuardCleanupCommand extends Command
{
    protected static $defaultName = 'app:bot-guard:cleanup';

    /**
     * @var BotGuardLogCleaner
     */
    private $cleaner;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(BotGuardLogCleaner $cleaner, EntityManagerInterface $em)
    {
        parent::__construct();
        $this->cleaner = $cleaner;
        $this->em = $em;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete Bot Guard logs older than N days.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention days. If omitted, value from BotGuardSettings is used.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->resolveDays($input->getOption('days'));
        $deleted = $this->cleaner->cleanupOlderThanDays($days);

        $io->success(sprintf('Bot Guard cleanup completed. Deleted rows: %d. Retention: %d days.', $deleted, $days));

        return Command::SUCCESS;
    }

    private function resolveDays($daysOption): int
    {
        if (null !== $daysOption && '' !== (string) $daysOption) {
            return max(1, (int) $daysOption);
        }

        /** @var BotGuardSettings|null $settings */
        $settings = $this->em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        if (!$settings instanceof BotGuardSettings) {
            return 60;
        }

        return max(1, $settings->getRetentionDays());
    }
}

