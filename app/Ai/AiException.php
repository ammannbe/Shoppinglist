<?php

namespace App\Ai;

use RuntimeException;

/**
 * The AI assistant is not available or its answer is unusable; the message is shown to the user.
 */
class AiException extends RuntimeException {}
