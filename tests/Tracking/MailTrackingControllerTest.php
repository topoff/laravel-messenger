<?php

use Illuminate\Support\Facades\URL;

it('tracks opens via pixel route', function () {
    $message = createMessage([
        'tracking_hash' => 'openhash001',
        'tracking_opens' => 0,
    ]);

    $response = $this->get(route('messenger.tracking.open', ['hash' => 'openhash001']));

    $response->assertOk();
    $response->assertHeader('Content-type', 'image/gif');

    $message->refresh();

    expect($message->tracking_opens)->toBe(1)
        ->and($message->tracking_opened_at)->not->toBeNull();
});

it('tracks clicks and redirects', function () {
    config()->set('messenger.tracking.inject_pixel', true);

    $message = createMessage([
        'tracking_hash' => 'clickhash001',
        'tracking_opens' => 0,
        'tracking_clicks' => 0,
    ]);

    $url = 'https://example.com/next?foo=bar';
    $signedUrl = URL::signedRoute('messenger.tracking.click', ['l' => $url, 'h' => 'clickhash001']);

    $this->get($signedUrl)->assertRedirect($url);

    $message->refresh();
    $clickedUrls = data_get($message->tracking_meta, 'clicked_urls', []);

    expect($message->tracking_clicks)->toBe(1)
        ->and($message->tracking_clicked_at)->not->toBeNull()
        ->and($message->tracking_opened_at)->not->toBeNull()
        ->and($clickedUrls[$url] ?? null)->toBe(1);
});

it('tracks opens and clicks for all messages sharing the same tracking hash', function () {
    config()->set('messenger.tracking.inject_pixel', true);

    $m1 = createMessage(['tracking_hash' => 'sharedhash001', 'tracking_opens' => 0, 'tracking_clicks' => 0]);
    $m2 = createMessage(['tracking_hash' => 'sharedhash001', 'tracking_opens' => 0, 'tracking_clicks' => 0]);

    $this->get(route('messenger.tracking.open', ['hash' => 'sharedhash001']))->assertOk();

    $url = 'https://example.com/shared';
    $signedUrl = URL::signedRoute('messenger.tracking.click', ['l' => $url, 'h' => 'sharedhash001']);
    $this->get($signedUrl)->assertRedirect($url);

    $m1->refresh();
    $m2->refresh();

    expect($m1->tracking_opens)->toBe(1)
        ->and($m2->tracking_opens)->toBe(1)
        ->and($m1->tracking_clicks)->toBe(1)
        ->and($m2->tracking_clicks)->toBe(1);
});
