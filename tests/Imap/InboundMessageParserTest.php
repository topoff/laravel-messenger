<?php

declare(strict_types=1);

use Topoff\Messenger\Services\Imap\InboundMessageParser;

function loadFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
}

it('parses headers including folded values and decodes Content-Type params', function () {
    $raw = loadFixture('postfix_hard_bounce.eml');
    $message = new InboundMessageParser()->parse($raw);

    expect($message->header('from'))->toContain('MAILER-DAEMON');
    expect($message->subject())->toBe('Undelivered Mail Returned to Sender');
    expect($message->header('content-type'))->toContain('multipart/report');
    expect($message->header('content-type'))->toContain('boundary="POSTFIX-BOUNDARY-001"');
});

it('flattens multipart parts and finds message/delivery-status', function () {
    $message = new InboundMessageParser()->parse(loadFixture('postfix_hard_bounce.eml'));

    $dsn = $message->firstPartByType('message/delivery-status');
    expect($dsn)->not->toBeNull();
    expect($dsn->body)->toContain('Status: 5.1.1');
    expect($dsn->body)->toContain('Final-Recipient: rfc822; broken@example.org');
});

it('decodes quoted-printable bodies', function () {
    $message = new InboundMessageParser()->parse(loadFixture('exchange_hard_bounce.eml'));

    $html = $message->firstPartByType('text/html');
    expect($html)->not->toBeNull();
    expect($html->body)->toContain('user.unknown@example.com');
    expect($html->body)->not->toContain('=2E');
});

it('extracts message/rfc822 returned-message part', function () {
    $message = new InboundMessageParser()->parse(loadFixture('exchange_hard_bounce.eml'));

    $rfc822 = $message->firstPartByType('message/rfc822');
    expect($rfc822)->not->toBeNull();
    expect($rfc822->body)->toContain('X-Topoff-Message-Id: 22223333-4444-7555-8666-777788889999');
});
