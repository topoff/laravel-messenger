<?php

declare(strict_types=1);

namespace Topoff\Messenger\Exceptions;

use RuntimeException;

/**
 * The SNS signing certificate could not be fetched (transient). Distinct from
 * an invalid signature so the endpoint can answer 5xx and SNS retries.
 */
class SnsCertificateUnavailableException extends RuntimeException {}
