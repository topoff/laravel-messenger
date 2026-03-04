<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('messenger.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('messages')) {
            return;
        }

        Schema::connection($connection)->table('messages', function (Blueprint $table): void {
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_hash')) {
                $table->string('tracking_hash', 64)->nullable()->index()->after('email_error');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_message_id')) {
                $table->string('tracking_message_id')->nullable()->index()->after('tracking_hash');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_sender_name')) {
                $table->string('tracking_sender_name')->nullable()->after('tracking_message_id');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_sender_email')) {
                $table->string('tracking_sender_email')->nullable()->after('tracking_sender_name');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_recipient_name')) {
                $table->string('tracking_recipient_name')->nullable()->after('tracking_sender_email');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_recipient_email')) {
                $table->string('tracking_recipient_email')->nullable()->after('tracking_recipient_name');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_subject')) {
                $table->string('tracking_subject')->nullable()->after('tracking_recipient_email');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_opens')) {
                $table->unsignedInteger('tracking_opens')->default(0)->after('tracking_subject');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_clicks')) {
                $table->unsignedInteger('tracking_clicks')->default(0)->after('tracking_opens');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_opened_at')) {
                $table->dateTime('tracking_opened_at')->nullable()->after('tracking_clicks');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_clicked_at')) {
                $table->dateTime('tracking_clicked_at')->nullable()->after('tracking_opened_at');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_meta')) {
                $table->json('tracking_meta')->nullable()->after('tracking_clicked_at');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_content')) {
                $table->text('tracking_content')->nullable()->after('tracking_meta');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('messages', 'tracking_content_path')) {
                $table->string('tracking_content_path')->nullable()->after('tracking_content');
            }
        });
    }

    public function down(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('messages')) {
            return;
        }

        Schema::connection($connection)->table('messages', function (Blueprint $table): void {
            foreach ([
                'tracking_hash',
                'tracking_message_id',
                'tracking_sender_name',
                'tracking_sender_email',
                'tracking_recipient_name',
                'tracking_recipient_email',
                'tracking_subject',
                'tracking_opens',
                'tracking_clicks',
                'tracking_opened_at',
                'tracking_clicked_at',
                'tracking_meta',
                'tracking_content',
                'tracking_content_path',
            ] as $column) {
                if (Schema::connection($this->getConnection())->hasColumn('messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
