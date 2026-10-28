<?php

namespace App\Sync;

use RuntimeException;

/**
 * A pushed document depends on data the server does not know yet
 * (e.g. an item of a list created offline). The client retries the batch.
 */
class RetryLaterException extends RuntimeException {}
