<?php

namespace App\Providers;

use App\Services\Ai\GeminiStructuredOutputClient;
use App\Services\Ai\OpenAiCompatibleStructuredOutputClient;
use App\Services\Ai\StructuredOutputClientInterface;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructuredOutputClientInterface::class, function () {
            $provider = config('services.ai.provider', 'gemini');
            $client = new Client([
                'base_uri' => rtrim((string) config('services.ai.base_uri'), '/').'/',
            ]);

            return match ($provider) {
                'gemini' => new GeminiStructuredOutputClient($client),
                'openai-compatible' => new OpenAiCompatibleStructuredOutputClient($client),
                default => throw new InvalidArgumentException("Unsupported AI_PROVIDER [{$provider}]. Use gemini or openai-compatible."),
            };
        });
    }
}
