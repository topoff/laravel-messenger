<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Topoff\Messenger\Mail\CustomMessageMail;
use Topoff\Messenger\Models\Message;

class SesSnsDashboardCustomMailController extends Controller
{
    public function show(Request $request)
    {
        return view('messenger::ses-sns-custom-mail-action', [
            'send_url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.custom-mail.send', now()->addMinutes(30)),
            'back_url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard', now()->addMinutes(30)),
            'preview_url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.custom-mail.preview', now()->addMinutes(30)),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function send(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'mailer' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('mail.mailers', [])))],
            'email' => ['required', 'email:rfc,dns'],
            'subject' => ['required', 'string', 'max:180'],
            'markdown' => ['required', 'string', 'max:65000'],
        ]);

        $message = $this->messageFromPayload($payload);
        $mailer = $payload['mailer'] ?? config('mail.default');
        Mail::mailer($mailer)->to($payload['email'])->send(new CustomMessageMail($message));

        return redirect()->to(URL::temporarySignedRoute('messenger.ses-sns.dashboard.custom-mail', now()->addMinutes(30)))
            ->with('messenger_custom_mail_result', [
                'ok' => true,
                'email' => $payload['email'],
                'subject' => $payload['subject'],
                'message' => 'Custom mail has been sent.',
            ]);
    }

    /**
     * @throws ValidationException
     */
    public function preview(Request $request)
    {
        $payload = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'markdown' => ['required', 'string', 'max:65000'],
        ]);

        $message = $this->messageFromPayload($payload);
        $html = (new CustomMessageMail($message))->render();

        return response($html);
    }

    /**
     * @param  array{subject: string, markdown: string}  $payload
     */
    protected function messageFromPayload(array $payload): Message
    {
        return new Message([
            'params' => ['subject' => (string) $payload['subject'], 'text' => (string) $payload['markdown']],
        ]);
    }
}
