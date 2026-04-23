<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Topoff\Messenger\Filament\Resources\MessageResource;
use Topoff\Messenger\Mail\CustomMessageMail;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Notifications\NovaChannelNotification;
use Topoff\Messenger\Repositories\MessageTypeRepository;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('tracking_by_type')
                ->label('Tracking by Type')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(fn () => MessageResource::getUrl('tracking-by-type')),

            Actions\Action::make('tracking_by_domain')
                ->label('Tracking by Domain')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->url(fn () => MessageResource::getUrl('tracking-by-domain')),

            Actions\Action::make('send_custom_email')
                ->label('Send Custom Email')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('mailer')
                        ->options(fn () => collect(config('mail.mailers', []))
                            ->keys()
                            ->mapWithKeys(fn (string $name) => [$name => strtoupper($name)])
                            ->toArray())
                        ->default(config('mail.default'))
                        ->required(),
                    Forms\Components\Select::make('ses_configuration_set')
                        ->options(fn () => collect(config('messenger.ses_sns.configuration_sets', []))
                            ->keys()
                            ->mapWithKeys(fn (string $key) => [$key => $key])
                            ->prepend('— none —', '')
                            ->toArray())
                        ->label('SES Configuration Set'),
                    Forms\Components\TextInput::make('recipient_email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('subject')
                        ->required()
                        ->maxLength(180),
                    Forms\Components\MarkdownEditor::make('markdown')
                        ->label('Email Body (Markdown)')
                        ->required()
                        ->maxLength(65000)
                        ->columnSpanFull(),
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Send At')
                        ->default(fn () => now()),
                    Forms\Components\Toggle::make('preview_only')
                        ->label('Preview only (do not queue)')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    $subject = trim($data['subject']);
                    $markdown = trim($data['markdown']);
                    $recipientEmail = trim($data['recipient_email']);
                    $mailer = $data['mailer'] ?: null;
                    $configSetKey = $data['ses_configuration_set'] ?: null;
                    $scheduledAt = $data['scheduled_at'] instanceof Carbon ? $data['scheduled_at'] : ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at']) : Date::now());

                    if ($data['preview_only'] ?? false) {
                        $previewKey = 'messenger:nova-custom-preview:' . Str::uuid();
                        Cache::put($previewKey, [
                            'subject' => $subject,
                            'markdown' => $markdown,
                            'receiver_count' => 1,
                        ], now()->addMinutes(10));

                        $previewUrl = URL::temporarySignedRoute('messenger.tracking.nova.custom-preview', now()->addMinutes(10), ['key' => $previewKey]);
                        $this->redirect($previewUrl);

                        return;
                    }

                    $user = request()->user();
                    $sender = $user ? ['class' => $user::class, 'id' => (int) $user->id] : ['class' => null, 'id' => null];

                    $messageType = resolve(MessageTypeRepository::class)
                        ->getFromTypeAndCustomer(CustomMessageMail::class);

                    $messageClass = config('messenger.models.message');
                    $messageRecord = $messageClass::create([
                        'channel' => 'email',
                        'message_type_id' => $messageType->id,
                        'sender_type' => $sender['class'],
                        'sender_id' => $sender['id'],
                        'params' => array_filter(['subject' => $subject, 'text' => $markdown, 'mailer' => $mailer, 'ses_configuration_set' => $configSetKey]),
                        'scheduled_at' => $scheduledAt,
                    ]);

                    $messageRecord->load('messageType');

                    try {
                        $pendingMail = $mailer
                            ? Mail::mailer($mailer)->to($recipientEmail)
                            : Mail::to($recipientEmail);
                        $pendingMail->send(new CustomMessageMail($messageRecord));

                        $messageRecord->sent_at = Date::now();
                        $messageRecord->save();

                        Notification::make()->success()->title("Email sent to {$recipientEmail}.")->send();
                    } catch (\Throwable $e) {
                        $messageRecord->error_at = Date::now();
                        $messageRecord->error_message = Str::limit($e->getMessage(), 245);
                        $messageRecord->save();

                        Notification::make()->danger()->title('Failed: ' . Str::limit($e->getMessage(), 100))->send();
                    }
                }),

            Actions\Action::make('send_notification')
                ->label('Send Notification')
                ->icon('heroicon-o-bell')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('channel')
                        ->options([
                            'mail' => 'Email',
                            'vonage' => 'SMS',
                        ])
                        ->default('vonage')
                        ->required(),
                    Forms\Components\TextInput::make('subject')
                        ->label('Subject (Email only)')
                        ->helperText('Required when channel is Email.'),
                    Forms\Components\TextInput::make('recipient')
                        ->required()
                        ->helperText('Email address or phone number depending on channel.'),
                    Forms\Components\Textarea::make('message')
                        ->required()
                        ->default(fn () => (string) config('messenger.notifications.default_message_footer', '')),
                ])
                ->action(function (array $data) {
                    $channel = $data['channel'];
                    $subject = trim($data['subject'] ?? '');
                    $message = trim($data['message']);
                    $recipient = trim($data['recipient']);

                    if ($channel === 'mail' && $subject === '') {
                        Notification::make()->danger()->title('Subject is required for email.')->send();

                        return;
                    }

                    $user = request()->user();
                    $sender = $user ? ['class' => $user::class, 'id' => (int) $user->id] : ['class' => null, 'id' => null];

                    $messageType = resolve(MessageTypeRepository::class)
                        ->getFromTypeAndCustomer(NovaChannelNotification::class);

                    $messageClass = config('messenger.models.message');
                    $messageRecord = $messageClass::create([
                        'channel' => $channel,
                        'message_type_id' => $messageType->id,
                        'sender_type' => $sender['class'],
                        'sender_id' => $sender['id'],
                        'params' => ['subject' => $subject, 'message' => $message],
                        'scheduled_at' => Date::now(),
                    ]);

                    $notification = new NovaChannelNotification($subject, $message, $channel);
                    $notification->messengerMessageId = $messageRecord->id;

                    try {
                        $notifiable = new AnonymousNotifiable;
                        $notifiable->route($channel, Str::replace(' ', '', $recipient));
                        $notifiable->notify($notification);

                        $messageRecord->sent_at = Date::now();
                        $messageRecord->save();

                        Notification::make()->success()->title("Notification sent to {$recipient}.")->send();
                    } catch (\Throwable $e) {
                        $messageRecord->error_at = Date::now();
                        $messageRecord->error_message = Str::limit($e->getMessage(), 245);
                        $messageRecord->save();

                        Notification::make()->danger()->title('Failed: ' . Str::limit($e->getMessage(), 100))->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
