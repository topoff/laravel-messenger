<?php

declare(strict_types=1);

use Topoff\Messenger\Support\SendFailureLogContext;

it('masks valid and syntactically broken e-mail addresses in error messages', function (string $input, string $expected) {
    expect(SendFailureLogContext::redact($input))->toBe($expected);
})->with([
    'plain' => ['Mailbox info@example.ch unavailable', 'Mailbox [email] unavailable'],
    'quoted broken' => ['Email "info@example.ch___deleted" does not comply', 'Email "[email]" does not comply'],
    'angle brackets' => ['Rejected <a.b+c@sub.example.org>.', 'Rejected <[email]>.'],
    'quoted local part' => ['Invalid address "john doe"@example.ch', 'Invalid address [email]'],
    'phone number' => ['Invalid recipient +41 79 123 45 67 rejected', 'Invalid recipient [number] rejected'],
    'compact phone' => ['To 0791234567 failed', 'To [number] failed'],
    'no address' => ['Domain contains illegal character.', 'Domain contains illegal character.'],
    'short code kept' => ['SMTP 554 error', 'SMTP 554 error'],
    'smtp status with long detail kept' => ['SMTP 550 5.1.10 Recipient not found', 'SMTP 550 5.1.10 Recipient not found'],
    'smtp status kept' => ['SMTP 554 5.7.1 Relay access denied', 'SMTP 554 5.7.1 Relay access denied'],
    'escaped quote in local part' => ['Invalid address "anna\\"muster"@example.ch', 'Invalid address [email]'],
]);

it('never passes the throwable itself into the context', function () {
    $context = SendFailureLogContext::forThrowable(new RuntimeException('Mailbox info@example.ch full', 552));

    expect($context)->not->toHaveKey('exception')
        ->and($context['error_code'])->toBe(552)
        ->and($context['exception_class'])->toBe(RuntimeException::class)
        ->and($context['exception_message'])->toBe('Mailbox [email] full');
});
