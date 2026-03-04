<?php

use Illuminate\Support\Facades\URL;

it('executes an allowed ses sns dashboard command and flashes the result', function () {
    config()->set('messenger.ses_sns.enabled', false);

    $url = URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(10), ['command' => 'check-tracking']);

    $response = $this->post($url);

    $response
        ->assertRedirect()
        ->assertSessionHas('messenger_ses_sns_command_result.command_key', 'check-tracking')
        ->assertSessionHas('messenger_ses_sns_command_result.command', 'messenger:ses-sns:check-tracking');
});

it('forbids unknown ses sns dashboard commands', function () {
    $url = URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(10), ['command' => 'unknown-command']);

    $this->post($url)->assertForbidden();
});
