<?php

namespace App\Providers\Contracts;

use App\Providers\ProviderResponse;

interface ProviderClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function send(array $messages, string $model, array $options = []): ProviderResponse;
}
