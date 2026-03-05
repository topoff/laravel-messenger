<?php

namespace Topoff\Messenger;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Laravel\Nova\Nova;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Topoff\Messenger\Console\CheckSesSendingCommand;
use Topoff\Messenger\Console\CheckSesSnsTrackingCommand;
use Topoff\Messenger\Console\SetupSesSendingCommand;
use Topoff\Messenger\Console\SetupSesSnsAllCommand;
use Topoff\Messenger\Console\SetupSesSnsTrackingCommand;
use Topoff\Messenger\Console\TeardownSesSnsTrackingCommand;
use Topoff\Messenger\Console\TestSesSnsEventsCommand;
use Topoff\Messenger\Contracts\SesSnsProvisioningApi;
use Topoff\Messenger\Jobs\CleanupMessengerTablesJob;
use Topoff\Messenger\Listeners\AddBccToEmailsListener;
use Topoff\Messenger\Listeners\LogEmailToMessageLogListener;
use Topoff\Messenger\Listeners\LogNotificationToMessageLogListener;
use Topoff\Messenger\Listeners\RecordNotificationSentListener;
use Topoff\Messenger\Nova\Resources\Message;
use Topoff\Messenger\Nova\Resources\MessageLog as MessageLogResource;
use Topoff\Messenger\Nova\Resources\MessageType as MessageTypeResource;
use Topoff\Messenger\Observers\MessageTypeObserver;
use Topoff\Messenger\Repositories\MessageTypeRepository;
use Topoff\Messenger\Services\SesSns\AwsSesSnsProvisioningApi;
use Topoff\Messenger\Tracking\MailTracker;

class MessengerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-messenger')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(SetupSesSnsAllCommand::class)
            ->hasCommand(SetupSesSnsTrackingCommand::class)
            ->hasCommand(CheckSesSnsTrackingCommand::class)
            ->hasCommand(SetupSesSendingCommand::class)
            ->hasCommand(CheckSesSendingCommand::class)
            ->hasCommand(TestSesSnsEventsCommand::class)
            ->hasCommand(TeardownSesSnsTrackingCommand::class)
            ->discoversMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MessageTypeRepository::class);
        $this->app->bind(SesSnsProvisioningApi::class, AwsSesSnsProvisioningApi::class);
    }

    public function packageBooted(): void
    {
        $this->registerObservers();
        $this->registerEventListeners();
        $this->registerCleanupSchedule();
        $this->registerRoutes();
        $this->registerNovaResources();
    }

    protected function registerObservers(): void
    {
        $messageTypeClass = config('messenger.models.message_type');
        $messageTypeClass::observe(MessageTypeObserver::class);
    }

    protected function registerEventListeners(): void
    {
        Event::listen(MessageSending::class, AddBccToEmailsListener::class);
        Event::listen(MessageSending::class, fn (MessageSending $event) => app(MailTracker::class)->messageSending($event));
        Event::listen(MessageSent::class, fn (MessageSent $event) => app(MailTracker::class)->messageSent($event));
        Event::listen(MessageSent::class, LogEmailToMessageLogListener::class);
        Event::listen(NotificationSent::class, LogNotificationToMessageLogListener::class);
        Event::listen(NotificationSent::class, RecordNotificationSentListener::class);
    }

    protected function registerCleanupSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! (bool) config('messenger.cleanup.schedule.enabled', true)) {
                return;
            }

            $cronExpression = (string) config('messenger.cleanup.schedule.cron', '17 3 * * *');
            $queue = config('messenger.cleanup.schedule.queue');

            $event = $schedule->job(new CleanupMessengerTablesJob, $queue)->cron($cronExpression)->name('messenger.cleanup');

            if ((bool) config('messenger.cleanup.schedule.without_overlapping', true)) {
                $event->withoutOverlapping();
            }

            if ((bool) config('messenger.cleanup.schedule.on_one_server', false)) {
                $event->onOneServer();
            }
        });
    }

    protected function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    protected function registerNovaResources(): void
    {
        $novaConfig = (array) config('messenger.tracking.nova', []);
        $isNovaEnabled = (bool) ($novaConfig['enabled'] ?? true);

        if (! class_exists(Nova::class) || ! $isNovaEnabled) {
            return;
        }

        $resourceClass = $novaConfig['resource'] ?? Message::class;
        if (is_string($resourceClass) && class_exists($resourceClass)) {
            $modelClass = config('messenger.models.message');
            if (is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $resourceClass::$model = $modelClass;
            }
        }

        if (! (bool) ($novaConfig['register_resource'] ?? false) || ! is_string($resourceClass) || ! class_exists($resourceClass)) {
            return;
        }

        $messageTypeModelClass = config('messenger.models.message_type');
        if (is_string($messageTypeModelClass) && is_subclass_of($messageTypeModelClass, Model::class)) {
            MessageTypeResource::$model = $messageTypeModelClass;
        }

        $messageLogModelClass = config('messenger.models.message_log');
        if (is_string($messageLogModelClass) && is_subclass_of($messageLogModelClass, Model::class)) {
            MessageLogResource::$model = $messageLogModelClass;
        }

        Nova::resources([
            $resourceClass,
            MessageTypeResource::class,
            MessageLogResource::class,
        ]);
    }
}
