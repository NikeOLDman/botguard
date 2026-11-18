<?php

declare(strict_types=1);

namespace App\Command;

use App\BotGuard\BotGuardCrawlerVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/** Warm real search/analytics bot identities before enabling production protection. */
final class BotGuardVerifyCrawlersCommand extends Command
{
    protected static $defaultName = 'app:bot-guard:verify-crawlers';
    private $verifier;

    public function __construct(BotGuardCrawlerVerifier $verifier)
    {
        parent::__construct();
        $this->verifier = $verifier;
    }

    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::OPTIONAL, 'Verify a single IP instead of reading an access log.')
            ->addOption('user-agent', null, InputOption::VALUE_REQUIRED, 'Crawler identity for the single IP.', 'Googlebot')
            ->addOption('access-log', null, InputOption::VALUE_REQUIRED, 'Read the last 4 MiB of a combined-format access log.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum distinct crawler IPs to verify.', '100')
            ->addOption('nginx', null, InputOption::VALUE_NONE, 'Output only verified IPs as nginx geo entries.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $candidates = [];
        $ip = $input->getArgument('ip');
        if (null !== $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $output->writeln('<error>Invalid IP.</error>');

                return 1;
            }
            $candidates[(string) $ip] = (string) $input->getOption('user-agent');
        } else {
            $path = (string) $input->getOption('access-log');
            $handle = '' === $path ? false : @fopen($path, 'r');
            if (false === $handle) {
                $output->writeln('<error>Specify an IP or a readable --access-log.</error>');

                return 1;
            }
            $offset = max(0, (int) filesize($path) - 4194304);
            if ($offset > 0) { fseek($handle, $offset); fgets($handle); }
            while (false !== ($line = fgets($handle))) {
                if (preg_match('/^(\S+) .*"([^"]*)"\s*$/D', trim($line), $match)
                    && filter_var($match[1], FILTER_VALIDATE_IP)
                    && preg_match('/Googlebot|Google-InspectionTool|Google-Site-Verification|GoogleOther|AdsBot-Google|Mediapartners-Google|Storebot-Google|Google-Read-Aloud|Google-Safety|Yandex|YaDirectFetcher|YaComBot/i', $match[2])) {
                    $candidates[$match[1]] = $match[2];
                }
            }
            fclose($handle);
            $candidates = array_slice($candidates, -max(1, min(1000, (int) $input->getOption('limit'))), null, true);
        }
        $pending = false;
        $verifiedCount = 0;
        foreach ($candidates as $address => $ua) {
            $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $address, 'HTTP_USER_AGENT' => $ua]);
            $request->attributes->set('_bot_guard_background_verification', true);
            $verified = $this->verifier->isVerified($request);
            if ($verified) ++$verifiedCount;
            $waiting = (bool) $request->attributes->get('_bot_guard_crawler_pending');
            $pending = $pending || $waiting;
            if ($input->getOption('nginx')) {
                if ($verified) $output->writeln($address.' 1;');
            } else {
                $output->writeln($address."\t".($verified ? 'verified' : ($waiting ? 'pending' : 'not verified'))."\t".$ua);
            }
        }

        return $pending || 0 === $verifiedCount ? 1 : 0;
    }
}
