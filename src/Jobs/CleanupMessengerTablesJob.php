<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupMessengerTablesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->cleanupTrackingContent();
        $this->cleanupMessages();
        $this->cleanupMessageLogs();
    }

    protected function cleanupTrackingContent(): void
    {
        $days = $this->nullableInt(config('messenger.cleanup.message_tracking_content_null_after_days'));
        if ($days === null) {
            return;
        }

        $messageModelClass = $this->modelClass('messenger.models.message');
        $cutoff = now()->subDays($days);

        // Clean up database-stored tracking content
        $this->queryWithTrashed($messageModelClass)
            ->whereNotNull('tracking_content')
            ->where('created_at', '<', $cutoff)
            ->update(['tracking_content' => null]);

        // Clean up filesystem-stored tracking content
        $this->deleteTrackingFiles(
            $this->queryWithTrashed($messageModelClass)
                ->whereNotNull('tracking_content_path')
                ->where('created_at', '<', $cutoff),
        );
    }

    protected function cleanupMessages(): void
    {
        $months = $this->nullableInt(config('messenger.cleanup.messages_delete_after_months'));
        if ($months === null) {
            return;
        }

        $messageModelClass = $this->modelClass('messenger.models.message');
        $query = $this->queryWithTrashed($messageModelClass)
            ->where('created_at', '<', now()->subMonths($months));

        // Delete filesystem tracking files before removing DB records
        $this->deleteTrackingFiles(clone $query);

        $this->deleteQuery($query, $messageModelClass);
    }

    protected function cleanupMessageLogs(): void
    {
        $months = $this->nullableInt(config('messenger.cleanup.message_log_delete_after_months'));
        if ($months === null) {
            return;
        }

        $messageLogModelClass = $this->modelClass('messenger.models.message_log');
        $messageLogModelClass::query()
            ->where('created_at', '<', now()->subMonths($months))
            ->delete();
    }

    protected function deleteTrackingFiles($query): void
    {
        $disk = config('messenger.tracking.tracker_filesystem');
        $storage = $disk ? Storage::disk($disk) : Storage::disk();

        $query->whereNotNull('tracking_content_path')
            ->chunkById(500, function ($messages) use ($storage): void {
                $paths = [];

                foreach ($messages as $message) {
                    if ($message->tracking_content_path) {
                        $paths[] = $message->tracking_content_path;
                    }
                }

                if ($paths !== []) {
                    try {
                        $storage->delete($paths);
                    } catch (\Throwable $e) {
                        Log::warning('CleanupMessengerTablesJob: Failed to delete tracking files.', [
                            'error' => $e->getMessage(),
                            'paths_count' => count($paths),
                        ]);
                    }

                    $messageClass = config('messenger.models.message');
                    $messageClass::whereIn('id', $messages->pluck('id'))
                        ->update(['tracking_content_path' => null]);
                }
            });
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function queryWithTrashed(string $modelClass)
    {
        $query = $modelClass::query();

        if ($this->usesSoftDeletes($modelClass) && method_exists($query, 'withTrashed')) {
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function deleteQuery($query, string $modelClass): void
    {
        if ($this->usesSoftDeletes($modelClass) && method_exists($query, 'forceDelete')) {
            $query->forceDelete();

            return;
        }

        $query->delete();
    }

    /**
     * @return class-string<Model>
     */
    protected function modelClass(string $configKey): string
    {
        $modelClass = config($configKey);

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_a($modelClass, Model::class, true)) {
            throw new \RuntimeException(sprintf('Invalid model class configured for "%s".', $configKey));
        }

        return $modelClass;
    }

    /**
     * Null or positive integer.
     */
    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function usesSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }
}
