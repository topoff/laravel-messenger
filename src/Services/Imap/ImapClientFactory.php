<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Topoff\Messenger\Exceptions\ImapConfigurationException;
use Topoff\Messenger\Exceptions\ImapPackageMissingException;

/**
 * Builds Webklex IMAP clients from messenger.imap.inboxes config.
 *
 * Webklex is an optional dependency; constructing a client throws
 * ImapPackageMissingException when it is not installed. Pure config lookups
 * (configuredInboxKeys, configFor, resolveInboxKeyForConfigurationSet) work
 * without webklex — they back the validation, scheduler wiring, and tests.
 */
class ImapClientFactory
{
    /**
     * @return list<string>
     */
    public function configuredInboxKeys(): array
    {
        return array_keys((array) config('messenger.imap.inboxes', []));
    }

    /**
     * @return array<string, mixed>
     */
    public function configFor(string $inboxKey): array
    {
        $inboxes = (array) config('messenger.imap.inboxes', []);
        if (! array_key_exists($inboxKey, $inboxes)) {
            throw ImapConfigurationException::unknownInbox($inboxKey);
        }

        $config = (array) $inboxes[$inboxKey];
        foreach (['host', 'username', 'password'] as $required) {
            if (empty($config[$required])) {
                throw ImapConfigurationException::missingField($inboxKey, $required);
            }
        }

        return $config;
    }

    /**
     * Reverse lookup: which inbox listens for replies/bounces tied to this
     * configuration set? Returns null when the set is not IMAP-monitored.
     */
    public function resolveInboxKeyForConfigurationSet(string $configurationSetKey): ?string
    {
        $sets = (array) config('messenger.ses_sns.configuration_sets', []);
        $entry = $sets[$configurationSetKey] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $inboxKey = $entry['imap_inbox'] ?? null;

        return is_string($inboxKey) && $inboxKey !== '' ? $inboxKey : null;
    }

    /**
     * Build a webklex IMAP client bound to the named inbox. Caller is responsible
     * for ->connect() / ->disconnect(). Returns an instance of
     * Webklex\PHPIMAP\Client; the loose `object` return type avoids a hard
     * link to the optional dependency.
     */
    public function make(string $inboxKey): object
    {
        $clientClass = '\\Webklex\\PHPIMAP\\Client';
        if (! class_exists($clientClass)) {
            throw ImapPackageMissingException::forClient();
        }

        $config = $this->configFor($inboxKey);

        $clientConfig = [
            'host' => $config['host'],
            'port' => (int) ($config['port'] ?? 993),
            'encryption' => $config['encryption'] ?? 'ssl',
            'validate_cert' => (bool) ($config['validate_cert'] ?? true),
            'username' => $config['username'],
            'password' => $config['password'],
            'protocol' => $config['protocol'] ?? 'imap',
        ];

        return new $clientClass($clientConfig);
    }
}
