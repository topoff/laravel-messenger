<?php

namespace Topoff\Messenger\Jobs\Concerns;

trait ExtractsSesMessageTags
{
    /**
     * Extract SES message tags from the SNS payload.
     *
     * Handles both SES tag formats:
     * - Array of objects: [['name' => 'k', 'value' => 'v'], ...]
     * - Map of arrays: ['key' => ['value'], ...]
     *
     * @return array<string, string>
     */
    protected function extractSesMessageTags(array $message): array
    {
        $raw = data_get($message, 'mail.tags', []);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $tags = collect($raw);

        if (array_is_list($raw)) {
            // Array-of-objects format: [['name' => 'k', 'value' => 'v'], ...]
            return $tags->mapWithKeys(fn ($tag) => [
                ($tag['name'] ?? $tag['Name'] ?? '') => ($tag['value'] ?? $tag['Value'] ?? ''),
            ])->filter(fn ($v, $k) => $k !== '')->toArray();
        }

        // Map-of-arrays format: ['key' => ['value'], ...]
        return $tags->map(fn ($v) => is_array($v) ? ($v[0] ?? '') : $v)->toArray();
    }
}
