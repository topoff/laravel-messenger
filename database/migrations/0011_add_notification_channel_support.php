<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Topoff\Messenger\NotificationHandler\MainNotificationHandler;
use Topoff\Messenger\Notifications\NovaChannelNotification;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('messenger.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        // (a) Make messagable_type / messagable_id nullable for standalone notifications
        Schema::connection($connection)->table('messages', function (Blueprint $table): void {
            $table->string('messagable_type', 50)->nullable()->change();
            $table->unsignedBigInteger('messagable_id')->nullable()->change();
        });

        // (b) Fix stale namespace references: Topoff\MailManager -> Topoff\Messenger
        $stalePrefix = 'Topoff\\MailManager\\';
        $newPrefix = 'Topoff\\Messenger\\';

        foreach (['notification_class', 'single_handler', 'bulk_handler'] as $column) {
            if (! Schema::connection($connection)->hasColumn('message_types', $column)) {
                continue;
            }

            DB::connection($connection)
                ->table('message_types')
                ->where($column, 'LIKE', $stalePrefix.'%')
                ->get()
                ->each(function (object $row) use ($connection, $column, $stalePrefix, $newPrefix): void {
                    DB::connection($connection)
                        ->table('message_types')
                        ->where('id', $row->id)
                        ->update([$column => str_replace($stalePrefix, $newPrefix, $row->{$column})]);
                });
        }

        // (c) Seed NovaChannelNotification MessageType
        if (! Schema::connection($connection)->hasTable('message_types')) {
            return;
        }

        $exists = DB::connection($connection)
            ->table('message_types')
            ->where('notification_class', NovaChannelNotification::class)
            ->exists();

        if ($exists) {
            return;
        }

        $data = [
            'notification_class' => NovaChannelNotification::class,
            'channel' => 'vonage',
            'single_handler' => MainNotificationHandler::class,
            'bulk_handler' => null,
            'direct' => true,
            'dev_bcc' => false,
            'required_sender' => false,
            'required_messagable' => false,
            'required_company_id' => false,
            'required_scheduled' => false,
            'required_text' => false,
            'required_params' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection($connection)->hasColumn('message_types', 'bulk_message_line')) {
            $data['bulk_message_line'] = 'Nova channel notification (SMS/email)';
        } elseif (Schema::connection($connection)->hasColumn('message_types', 'developer_comment')) {
            $data['developer_comment'] = 'Nova channel notification (SMS/email)';
        }

        DB::connection($connection)->table('message_types')->insert($data);
    }

    public function down(): void
    {
        $connection = $this->getConnection();

        if (Schema::connection($connection)->hasTable('message_types')) {
            DB::connection($connection)
                ->table('message_types')
                ->where('notification_class', NovaChannelNotification::class)
                ->delete();
        }

        Schema::connection($connection)->table('messages', function (Blueprint $table): void {
            $table->string('messagable_type', 50)->nullable(false)->change();
            $table->unsignedBigInteger('messagable_id')->nullable(false)->change();
        });
    }
};
