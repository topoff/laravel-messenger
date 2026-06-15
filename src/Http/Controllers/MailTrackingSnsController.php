<?php

declare(strict_types=1);

namespace Topoff\Messenger\Http\Controllers;

use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Topoff\Messenger\Services\SesSns\SnsNotificationProcessor;

class MailTrackingSnsController extends Controller
{
    public function __construct(protected SnsNotificationProcessor $processor) {}

    public function callback(Request $request): string
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = json_decode((string) $request->getContent(), true) ?: [];
        }

        if (! $this->signatureIsValid($payload)) {
            return 'invalid signature';
        }

        return $this->processor->processEnvelope($payload);
    }

    /**
     * Verify the SNS message signature when enabled. SNS signs every HTTP
     * delivery; verifying the signature prevents forged tracking events from
     * anyone who learns the public endpoint and topic ARN.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signatureIsValid(array $payload): bool
    {
        if (! (bool) config('messenger.tracking.sns.verify_signature', false)) {
            return true;
        }

        if (! class_exists(MessageValidator::class)) {
            return true;
        }

        try {
            new MessageValidator()->validate(new SnsMessage($payload));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
