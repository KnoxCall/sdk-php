<?php

declare(strict_types=1);

namespace KnoxCall;

/** The request timed out. ->requestSent distinguishes connect-timeout (false) from read-timeout (true). */
class ConnectionTimeoutException extends ConnectionException {}
