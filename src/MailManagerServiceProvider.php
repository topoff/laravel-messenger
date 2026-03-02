<?php

namespace Topoff\MailManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Laravel\Nova\Nova;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Topoff\MailManager\Console\CheckSesSendingCommand;
use Topoff\MailManager\Console\CheckSesSnsTrackingCommand;
use Topoff\MailManager\Console\SetupSesSendingCommand;
use Topoff\MailManager\Console\SetupSesSnsAllCommand;
use Topoff\MailManager\Console\SetupSesSnsTrackingCommand;
use Topoff\MailManager\Console\TeardownSesSnsTrackingCommand;
use Topoff\MailManager\Console\TestSesSnsEventsCommand;
use Topoff\MailManager\Contracts\SesSnsProvisioningApi;
use Topoff\MailManager\Jobs\CleanupMailManagerTablesJob;
use Topoff\MailManager\Listeners\AddBccToEmailsListener;
use Topoff\MailManager\Listeners\LogEmailsListener;
use Topoff\MailManager\Listeners\LogNotificationListener;
use Topoff\MailManager\Nova\Resources\EmailLog as EmailLogResource;
use Topoff\MailManager\Nova\Resources\Message;
use Topoff\MailManager\Nova\Resources\MessageType as MessageTypeResource;
use Topoff\MailManager\Nova\Resources\NotificationLog as NotificationLogResource;
use Topoff\MailManager\Observers\MessageTypeObserver;
use Topoff\MailManager\Repositories\MessageTypeRepository;
use Topoff\MailManager\Services\SesSns\AwsSesSnsProvisioningApi;
use Topoff\MailManager\Tracking\MailTracker;

class MailManagerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-mail-manager')
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
        $messageTypeClass = config('mail-manager.models.message_type');
        $messageTypeClass::observe(MessageTypeObserver::class);
    }

    protected function registerEventListeners(): void
    {
        Event::listen(MessageSending::class, AddBccToEmailsListener::class);
        Event::listen(MessageSending::class, fn (MessageSending $event) => app(MailTracker::class)->messageSending($event));
        Event::listen(MessageSent::class, fn (MessageSent $event) => app(MailTracker::class)->messageSent($event));
        Event::listen(MessageSent::class, LogEmailsListener::class);
        Event::listen(NotificationSent::class, LogNotificationListener::class);
    }

    protected function registerCleanupSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! (bool) config('mail-manager.cleanup.schedule.enabled', true)) {
                return;
            }

            $cronExpression = (string) config('mail-manager.cleanup.schedule.cron', '17 3 * * *');
            $queue = config('mail-manager.cleanup.schedule.queue');

            $event = $schedule->job(new CleanupMailManagerTablesJob, $queue)->cron($cronExpression)->name('mail-manager.cleanup');

            if ((bool) config('mail-manager.cleanup.schedule.without_overlapping', true)) {
                $event->withoutOverlapping();
            }

            if ((bool) config('mail-manager.cleanup.schedule.on_one_server', false)) {
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
        $novaConfig = (array) config('mail-manager.tracking.nova', []);
        $isNovaEnabled = (bool) ($novaConfig['enabled'] ?? true);

        if (! class_exists(Nova::class) || ! $isNovaEnabled) {
            return;
        }

        $resourceClass = $novaConfig['resource'] ?? Message::class;
        if (is_string($resourceClass) && class_exists($resourceClass)) {
            $modelClass = config('mail-manager.models.message');
            if (is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $resourceClass::$model = $modelClass;
            }
        }

        if (! (bool) ($novaConfig['register_resource'] ?? false) || ! is_string($resourceClass) || ! class_exists($resourceClass)) {
            return;
        }

        $messageTypeModelClass = config('mail-manager.models.message_type');
        if (is_string($messageTypeModelClass) && is_subclass_of($messageTypeModelClass, Model::class)) {
            MessageTypeResource::$model = $messageTypeModelClass;
        }

        $emailLogModelClass = config('mail-manager.models.email_log');
        if (is_string($emailLogModelClass) && is_subclass_of($emailLogModelClass, Model::class)) {
            EmailLogResource::$model = $emailLogModelClass;
        }

        $notificationLogModelClass = config('mail-manager.models.notification_log');
        if (is_string($notificationLogModelClass) && is_subclass_of($notificationLogModelClass, Model::class)) {
            NotificationLogResource::$model = $notificationLogModelClass;
        }

        Nova::resources([
            $resourceClass,
            MessageTypeResource::class,
            EmailLogResource::class,
            NotificationLogResource::class,
        ]);
    }
}
