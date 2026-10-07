<?php

declare(strict_types=1);

namespace BWC\Visa;

/** Problem caused by the request (bad input); the message is safe to show to the caller (HTTP 400). */
class ClientError extends \RuntimeException
{
}
