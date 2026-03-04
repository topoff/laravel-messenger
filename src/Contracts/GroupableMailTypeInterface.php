<?php

namespace Topoff\Messenger\Contracts;

/**
 * This interface has to be implemented by all MailHandlers which should be groupable
 * to the standard BulkMail.
 */
interface GroupableMailTypeInterface
{
    /**
     * Build the data for the Bulk Mail
     */
    public function buildDataBulkMail(): string;
}
