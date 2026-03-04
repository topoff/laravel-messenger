<?php

namespace Topoff\Messenger\Contracts;

interface MessageReceiverInterface
{
    /**
     * The email address of the Model
     */
    public function getEmail(): string;

    /**
     * The URI for the model, used for direct email links
     */
    public function getResourceUri(): string;

    /**
     * Set the Email to invalid on the Receiver Model, that we can prevent sending emails to invalid addresses
     *
     * @param  bool  $isManualCall  - if the call is a manual call, or if its automatic (e.g. when the email is bounced)
     */
    public function setEmailToInvalid(bool $isManualCall = true): void;

    /**
     * Check if the email is valid
     */
    public function getEmailIsValid(): bool;

    /**
     * Return the preferred locale for the receiver, used for sending emails in the correct language
     */
    public function preferredLocale(): string;
}
