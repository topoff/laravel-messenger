<?php

declare(strict_types=1);

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use Topoff\Messenger\Services\Imap\ImapBounceProcessor;
use Topoff\Messenger\Services\Imap\ImapClientFactory;
use Topoff\Messenger\Services\Imap\ProcessingResult;
use Topoff\Messenger\Services\Imap\WebklexInboundMessageSource;

/**
 * One inbox-sweep job. Pulled in by the scheduler (registered in
 * MessengerServiceProvider) or run on-demand via messenger:imap:fetch.
 *
 * Marked ShouldBeUnique so the scheduler does not stack overlapping runs
 * against the same inbox when one pass takes longer than the cron interval.
 */
class ProcessImapInboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public string $inboxKey) {}

    public function uniqueId(): string
    {
        return 'messenger.imap.inbox.'.$this->inboxKey;
    }

    public function handle(ImapClientFactory $factory, ImapBounceProcessor $processor): ProcessingResult
    {
        $config = $factory->configFor($this->inboxKey);
        $folderName = (string) ($config['folder'] ?? 'INBOX');
        $limit = (int) ($config['max_messages_per_run'] ?? 200);
        $sinceDays = isset($config['fetch_since_days']) ? (int) $config['fetch_since_days'] : null;

        $client = null;
        try {
            $client = $factory->make($this->inboxKey);
            $client->connect();
            $folder = $client->getFolder($folderName);
            if ($folder === null) {
                throw new \RuntimeException("IMAP folder '{$folderName}' not found on inbox '{$this->inboxKey}'.");
            }

            $source = new WebklexInboundMessageSource(
                inboxKey: $this->inboxKey,
                folder: $folder,
                afterProcess: (array) config('messenger.imap.after_process'),
                folders: (array) config('messenger.imap.folders'),
                sinceDays: $sinceDays,
            );

            $result = $processor->process($source, $limit);

            Log::info('ProcessImapInboxJob: pass complete', $result->toArray());

            return $result;
        } catch (Throwable $e) {
            Log::error('ProcessImapInboxJob: failed', [
                'inbox_key' => $this->inboxKey,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            throw $e;
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // ignore
            }
        }
    }
}
