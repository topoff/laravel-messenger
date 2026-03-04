<?php

use Illuminate\Support\Facades\URL;

it('renders tracked html from nova preview route', function () {
    $message = createMessage([
        'tracking_hash' => 'nova-preview-001',
        'tracking_content' => '<html><body><h1>Nova Preview</h1></body></html>',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'messenger.tracking.nova.preview',
        now()->addMinutes(5),
        ['id' => $message->id]
    );

    $this->get($signedUrl)
        ->assertOk()
        ->assertSee('Nova Preview', false);
});

it('rejects unsigned nova preview requests', function () {
    $message = createMessage(['tracking_hash' => 'nova-preview-002']);

    $this->get(route('messenger.tracking.nova.preview', ['id' => $message->id]))
        ->assertForbidden();
});
