<?php

declare(strict_types=1);

namespace BWC\Visa;

/** A required backend (analysis service, PDF converter) failed; the message is safe to show (HTTP 502). */
final class UpstreamError extends \RuntimeException
{
}
