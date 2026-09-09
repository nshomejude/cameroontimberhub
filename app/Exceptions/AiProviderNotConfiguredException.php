<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when any AI feature is invoked before an admin has set a real API key for the active provider. */
class AiProviderNotConfiguredException extends RuntimeException
{
    public function __construct(string $provider = '')
    {
        parent::__construct($provider !== '' ? "AI provider [{$provider}] is not configured yet." : 'No AI provider is configured yet.');
    }
}
