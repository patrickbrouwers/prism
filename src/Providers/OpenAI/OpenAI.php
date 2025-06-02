<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI;

use Closure;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\OpenAI\Handlers\Embeddings;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Handlers\Structured;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

readonly class OpenAI implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $url,
        public ?string $organization,
        public ?string $project,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->guzzleClient(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function client(array $options, array $retry): PendingRequest
    {
        return Http::withHeaders(array_filter([
            'Authorization' => $this->apiKey !== '' && $this->apiKey !== '0' ? sprintf('Bearer %s', $this->apiKey) : null,
            'OpenAI-Organization' => $this->organization,
            'OpenAI-Project' => $this->project,
        ]))
            ->withOptions($options)
            ->retry(...$retry)
            ->baseUrl($this->url);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function guzzleClient(array $options, array $retry): Client
    {
        $stack = HandlerStack::create();

        // Add retry middleware based on Laravel retry configuration
        $this->addRetryMiddleware($stack, $retry);

        $headers = array_filter([
            'Authorization' => $this->apiKey !== '' && $this->apiKey !== '0' ? sprintf('Bearer %s', $this->apiKey) : null,
            'OpenAI-Organization' => $this->organization,
            'OpenAI-Project' => $this->project,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        return new Client(array_merge([
            'base_uri' => rtrim($this->url, '/').'/',
            'headers' => $headers,
            'handler' => $stack,
            'timeout' => 60,
            'connect_timeout' => 10,
        ], $options));
    }

    /**
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function addRetryMiddleware(HandlerStack $stack, array $retry): void
    {
        if ($retry === []) {
            return;
        }

        $times = $retry[0] ?? 0;
        $delay = $retry[1] ?? 100;
        $when = $retry[2] ?? null;

        // Convert Laravel retry format to Guzzle format
        if (is_int($times)) {
            $maxRetries = $times;
        } elseif (is_array($times)) {
            $maxRetries = count($times);
        } else {
            $maxRetries = 0;
        }

        if ($maxRetries <= 0) {
            return;
        }

        $decider = function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null
        ) use ($maxRetries, $when): bool {
            if ($retries >= $maxRetries) {
                return false;
            }

            // If custom when callback is provided, use it
            if ($when && is_callable($when)) {
                return $when($exception, $request, $response);
            }

            // Default retry logic for 5xx errors and connection issues
            if ($exception instanceof \Throwable) {
                return true;
            }

            if ($response && $response->getStatusCode() >= 500) {
                return true;
            }
            // Retry on 429 (rate limit)
            return $response && $response->getStatusCode() === 429;
        };

        $delayFunction = function (int $retries) use ($delay, $times): int {
            if (is_array($times) && isset($times[$retries - 1])) {
                return $times[$retries - 1];
            }

            if (is_int($delay)) {
                return $delay;
            }

            if (is_callable($delay)) {
                return $delay($retries);
            }

            // Exponential backoff as default
            return (int) (100 * 2 ** ($retries - 1));
        };

        $stack->push(
            Middleware::retry($decider, $delayFunction),
            'retry'
        );
    }
}
