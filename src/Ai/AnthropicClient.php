<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;

/**
 * Lean client for the Anthropic Messages API, built on the WordPress HTTP API.
 *
 * Deliberately no additional HTTP stack: the plugin runs alongside forty-eight
 * others on the same installation, and wp_remote_post respects the site's proxy
 * settings and time limits.
 */
final class AnthropicClient
{
    public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    public const BATCH_ENDPOINT = 'https://api.anthropic.com/v1/messages/batches';

    private function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $workspaceId
    ) {
    }

    /** Pipeline step => setting with a model of its own (empty: main model). */
    public const STEP_MODEL_OPTIONS = [
        'faktenpruefung' => 'anthropic_model_factcheck',
        'metadaten'      => 'anthropic_model_metadata',
        'woerterbuch'    => 'anthropic_model_dictionary',
        'research'       => 'anthropic_model_research',
    ];

    /**
     * The model for a pipeline step: the step's own setting if filled in,
     * otherwise the main model — then the filter `sonoquill_model_for_step`.
     *
     * Steps used by the core: redigat, metadaten, faktenpruefung,
     * woerterbuch; research for add-ons with web research. null or '' means
     * no particular step.
     */
    public static function modelForStep(?string $step = null): string
    {
        $step = (string) $step;
        $model = trim(Options::get('anthropic_model'));

        $option = self::STEP_MODEL_OPTIONS[$step] ?? '';
        if ($option !== '' && trim(Options::get($option)) !== '') {
            $model = trim(Options::get($option));
        }

        /**
         * Filter: the model used for one pipeline step.
         *
         * @param string $model Model ID from the settings.
         * @param string $step  Step name (redigat, metadaten, faktenpruefung, woerterbuch, research) or ''.
         */
        return trim((string) apply_filters('sonoquill_model_for_step', $model, $step));
    }

    /**
     * @param string|null $step Pipeline step, see modelForStep().
     *
     * @throws AnthropicException
     */
    public static function fromSettings(?string $step = null): self
    {
        try {
            $key = Options::secret('anthropic_api_key');
        } catch (CryptoException $e) {
            throw new AnthropicException(__('Anthropic credentials could not be read: ', 'sonoquill') . $e->getMessage(), 0, $e);
        }

        if ($key === '') {
            throw new AnthropicException(__('No Anthropic API key has been configured.', 'sonoquill'));
        }

        $model = self::modelForStep($step);
        if ($model === '') {
            throw new AnthropicException(__('No Anthropic model has been selected.', 'sonoquill'));
        }

        return new self($key, $model, Options::get('anthropic_workspace_id'));
    }

    /**
     * The same client with another model — for a single call that needs a
     * different one.
     */
    public function withModel(string $model): self
    {
        $model = trim($model);
        if ($model === '') {
            throw new \InvalidArgumentException('Empty model name.');
        }

        return new self($this->apiKey, $model, $this->workspaceId);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One call, one response.
     *
     * The user part is either text or a list of content blocks — the latter
     * for the sky preview, which sends the book's monthly pages along as a
     * PDF document.
     *
     * @param string|list<array<string,mixed>> $user
     * @param array<string,mixed>              $options Additional request fields,
     *                                                  e.g. output_config for enforced JSON.
     *
     * @throws AnthropicException
     */
    public function complete(string $system, string|array $user, array $options = []): AnthropicResponse
    {
        $timeout = (int) ($options['__timeout'] ?? 600);

        return self::interpret($this->send($this->payload($system, $user, $options), $timeout));
    }

    /**
     * The request exactly as it is sent to the API — identical for direct
     * calls and for batches, so that both paths produce the same result.
     *
     * @param string|list<array<string,mixed>> $user
     * @param array<string,mixed>              $options
     *
     * @return array<string,mixed>
     */
    public function payload(string $system, string|array $user, array $options = []): array
    {
        $payload = array_merge([
            'model'      => $this->model,
            'max_tokens' => 32000,
            // Adaptive thinking: the rewrite has strict rules for numbers; this
            // is not a task that succeeds without reasoning. On Opus 5.5 it is
            // always on anyway; it is controlled via output_config.effort.
            'thinking'   => ['type' => 'adaptive'],
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ], $options);

        unset($payload['__timeout']);

        return $payload;
    }

    /**
     * Submits a single request as a Message Batch — half the price, with the
     * result usually available within an hour.
     *
     * @param array<string,mixed> $payload
     *
     * @throws AnthropicException
     */
    public function createBatch(string $customId, array $payload): string
    {
        $payload['model'] ??= $this->model;
        $decoded = $this->request('POST', self::BATCH_ENDPOINT, [
            'requests' => [['custom_id' => $customId, 'params' => $payload]],
        ]);

        $id = (string) ($decoded['id'] ?? '');
        if ($id === '') {
            throw new AnthropicException(__('The batch was submitted, but no identifier was returned.', 'sonoquill'));
        }

        return $id;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws AnthropicException
     */
    public function retrieveBatch(string $id): array
    {
        return $this->request('GET', self::BATCH_ENDPOINT . '/' . rawurlencode($id));
    }

    /**
     * The results of a completed batch, one row per request.
     *
     * @return list<array<string,mixed>>
     *
     * @throws AnthropicException
     */
    public function batchResults(string $resultsUrl): array
    {
        if (!str_starts_with($resultsUrl, 'https://api.anthropic.com/')) {
            throw new AnthropicException(__('Unexpected address for batch results.', 'sonoquill'));
        }

        $response = wp_remote_get($resultsUrl, ['timeout' => 120, 'headers' => $this->headers()]);
        if (is_wp_error($response)) {
            throw new AnthropicException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            /* translators: %d: HTTP status code of the batch results request */
            throw new AnthropicException(sprintf(__('Batch results: HTTP %d.', 'sonoquill'), $code));
        }

        $rows = [];
        foreach (preg_split('/\R/', (string) wp_remote_retrieve_body($response)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     *
     * @throws AnthropicException
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $args = ['method' => $method, 'timeout' => 60, 'headers' => $this->headers()];
        if ($body !== null) {
            $json = wp_json_encode($body);
            if ($json === false) {
                throw new AnthropicException(__('The request could not be encoded as JSON.', 'sonoquill'));
            }
            $args['body'] = $json;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new AnthropicException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code !== 200 || !is_array($decoded)) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : __('no readable response', 'sonoquill');

            /* translators: 1: HTTP status code, 2: error message from the API */
            throw new AnthropicException(sprintf(__('Anthropic responded with HTTP %1$d: %2$s', 'sonoquill'), $code, $message));
        }

        return $decoded;
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        $headers = [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];

        if ($this->workspaceId !== '') {
            $headers['anthropic-workspace-id'] = $this->workspaceId;
        }

        return $headers;
    }

    /**
     * Sends a prepared request and returns the decoded response.
     *
     * For calls with server tools (web search), where the caller has to
     * evaluate the content blocks itself and continue on `pause_turn`.
     * Errors and refusals throw just as in complete().
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     *
     * @throws AnthropicException
     */
    public function send(array $payload, int $timeout = 600): array
    {
        $payload['model'] ??= $this->model;

        $body = wp_json_encode($payload);
        if ($body === false) {
            throw new AnthropicException(__('The request could not be encoded as JSON.', 'sonoquill'));
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => $timeout,
            'headers' => $this->headers(),
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            throw new AnthropicException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        return self::decode(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response)
        );
    }

    /**
     * @return array<string,mixed>
     *
     * @throws AnthropicException
     */
    private static function decode(int $code, string $raw): array
    {
        $decoded = json_decode($raw, true);

        if ($code !== 200 || !is_array($decoded)) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : mb_substr($raw, 0, 300);

            /* translators: 1: HTTP status code, 2: error message or start of the raw response */
            throw new AnthropicException(sprintf(__('Anthropic responded with HTTP %1$d: %2$s', 'sonoquill'), $code, $message));
        }

        self::ensureNotRefused($decoded);

        return $decoded;
    }

    /**
     * Safety classifiers can refuse a request. This arrives with HTTP 200
     * (or as a successful batch result) and must be checked before the
     * content is read.
     *
     * @param array<string,mixed> $decoded
     *
     * @throws AnthropicException
     */
    public static function ensureNotRefused(array $decoded): void
    {
        if ((string) ($decoded['stop_reason'] ?? '') === 'refusal') {
            $category = (string) ($decoded['stop_details']['category'] ?? __('unknown', 'sonoquill'));

            /* translators: %s: refusal category reported by the API */
            throw new AnthropicException(sprintf(__('The request was not accepted (%s).', 'sonoquill'), $category));
        }
    }

    /**
     * @param array<string,mixed> $decoded
     *
     * @throws AnthropicException
     */
    public static function interpret(array $decoded, bool $batch = false, bool $alreadyBooked = false): AnthropicResponse
    {
        $stopReason = (string) ($decoded['stop_reason'] ?? '');

        // Check the token limit first: when it is reached, the text is often
        // missing entirely because thinking used up everything — in that case
        // this is the cause, not "no text".
        if ($stopReason === 'max_tokens') {
            throw new AnthropicException(sprintf(
                /* translators: 1: number of output tokens, 2: number of thinking tokens among them */
                __('The response was aborted because the token limit was reached (%1$d output tokens, %2$d of them thinking).', 'sonoquill'),
                (int) ($decoded['usage']['output_tokens'] ?? 0),
                (int) ($decoded['usage']['output_tokens_details']['thinking_tokens'] ?? 0)
            ));
        }

        $text = '';
        foreach ((array) ($decoded['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if (trim($text) === '') {
            throw new AnthropicException(__('The response contains no text.', 'sonoquill'));
        }

        return new AnthropicResponse(
            $text,
            (int) ($decoded['usage']['input_tokens'] ?? 0),
            (int) ($decoded['usage']['output_tokens'] ?? 0),
            $stopReason,
            (string) ($decoded['model'] ?? ''),
            (int) ($decoded['usage']['cache_creation_input_tokens'] ?? 0),
            (int) ($decoded['usage']['cache_read_input_tokens'] ?? 0),
            $batch,
            $alreadyBooked
        );
    }
}
