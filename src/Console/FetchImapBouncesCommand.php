<?php

declare(strict_types=1);

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Jobs\ProcessImapInboxJob;
use Topoff\Messenger\Services\Imap\ImapBounceProcessor;
use Topoff\Messenger\Services\Imap\ImapClientFactory;

class FetchImapBouncesCommand extends Command
{
    protected $signature = 'messenger:imap:fetch
        {inbox? : Inbox key from messenger.imap.inboxes. Omit to list all configured inboxes.}
        {--limit= : Override messenger.imap.inboxes.<key>.max_messages_per_run for this run.}
        {--dry-run : Print resolved config and what would be processed, without connecting to IMAP.}';

    protected $description = 'Fetch and classify bounces / complaints / replies from the reply-to inbox(es).';

    public function handle(ImapClientFactory $factory, ImapBounceProcessor $processor): int
    {
        $inboxKey = $this->argument('inbox');

        if (! is_string($inboxKey) || $inboxKey === '') {
            return $this->listInboxes($factory);
        }

        try {
            $config = $factory->configFor($inboxKey);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->printDryRun($inboxKey, $config);
        }

        $limit = $this->option('limit');
        $job = new ProcessImapInboxJob($inboxKey);
        if (is_numeric($limit)) {
            $config['max_messages_per_run'] = (int) $limit;
            config()->set("messenger.imap.inboxes.{$inboxKey}", $config);
        }

        try {
            $result = $job->handle($factory, $processor);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("IMAP fetch finished for inbox '{$inboxKey}':");
        foreach ($result->toArray() as $k => $v) {
            $this->line(sprintf('  %s: %s', $k, (string) $v));
        }

        return self::SUCCESS;
    }

    private function listInboxes(ImapClientFactory $factory): int
    {
        $keys = $factory->configuredInboxKeys();
        if ($keys === []) {
            $this->warn('No IMAP inboxes configured. See messenger.imap.inboxes in your config.');

            return self::SUCCESS;
        }

        $this->info('Configured IMAP inboxes:');
        foreach ($keys as $key) {
            $this->line("  - {$key}");
        }
        $this->line('');
        $this->line('Run with an inbox key, e.g.: php artisan messenger:imap:fetch '.$keys[0]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function printDryRun(string $inboxKey, array $config): int
    {
        $this->info("Dry run for inbox '{$inboxKey}':");
        $safe = [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? 993,
            'username' => $config['username'] ?? null,
            'folder' => $config['folder'] ?? 'INBOX',
            'max_messages_per_run' => $config['max_messages_per_run'] ?? 200,
            'fetch_since_days' => $config['fetch_since_days'] ?? null,
            'after_process' => config('messenger.imap.after_process'),
            'folders' => config('messenger.imap.folders'),
        ];
        foreach ($safe as $k => $v) {
            $this->line(sprintf('  %s: %s', $k, is_scalar($v) || $v === null ? (string) $v : json_encode($v)));
        }

        $this->line('');
        $this->comment('No IMAP connection was attempted (--dry-run).');

        return self::SUCCESS;
    }
}
