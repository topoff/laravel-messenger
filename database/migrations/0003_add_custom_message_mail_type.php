<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Topoff\MailManager\Mail\CustomMessageMail;
use Topoff\MailManager\MailHandler\MainMailHandler;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('mail-manager.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('message_types')) {
            return;
        }

        $exists = DB::connection($connection)
            ->table('message_types')
            ->where('mail_class', CustomMessageMail::class)
            ->exists();

        if ($exists) {
            return;
        }

        $data = [
            'mail_class' => CustomMessageMail::class,
            'single_mail_handler' => MainMailHandler::class,
            'bulk_mail_handler' => null,
            'direct' => true,
            'dev_bcc' => true,
            'required_sender' => false,
            'required_messagable' => false,
            'required_company_id' => false,
            'required_scheduled' => true,
            'required_mail_text' => true,
            'required_params' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection($connection)->hasColumn('message_types', 'bulk_message_line')) {
            $data['bulk_message_line'] = 'Custom messages from Nova action';
        } elseif (Schema::connection($connection)->hasColumn('message_types', 'developer_comment')) {
            $data['developer_comment'] = 'Custom messages from Nova action';
        }

        DB::connection($connection)->table('message_types')->insert($data);
    }

    public function down(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('message_types')) {
            return;
        }

        DB::connection($connection)
            ->table('message_types')
            ->where('mail_class', CustomMessageMail::class)
            ->delete();
    }
};
