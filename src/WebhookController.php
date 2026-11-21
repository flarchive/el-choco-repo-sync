<?php

namespace ElChoco\RepoSync;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class WebhookController implements RequestHandlerInterface
{
    public function __construct(private readonly Configuration $config, private readonly Jobs $jobs) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];
        try {
            $secret = $this->config->get('secret');
            if ($this->config->get('enabled') !== '1' || strlen($secret) < 32) throw new SyncException('not_configured', 503);
            $body = $request->getBody();
            if ($body->isSeekable()) $body->rewind();
            $raw = $body->read(2097153);
            if (strlen($raw) > 2097152) throw new SyncException('too_large', 413);
            $signature = $request->getHeaderLine('X-Hub-Signature-256');
            if (!preg_match('/^sha256=[a-f0-9]{64}$/D', $signature) || !hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
                throw new SyncException('invalid_signature', 401);
            }
            $event = $request->getHeaderLine('X-GitHub-Event');
            if ($event === 'ping') return new JsonResponse(['status' => 'pong'], 200, $headers);
            if (!in_array($event, ['push', 'release'], true)) return new JsonResponse(['status' => 'ignored'], 200, $headers);
            $delivery = $request->getHeaderLine('X-GitHub-Delivery');
            if (!preg_match('/^[a-zA-Z0-9-]{8,80}$/D', $delivery)) throw new SyncException('invalid_payload');
            try { $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new SyncException('invalid_payload'); }
            if (!is_array($payload)) throw new SyncException('invalid_payload');
            $count = $this->jobs->receive($event, $delivery, $payload, $this->config->mappings());
            return new JsonResponse(['status' => $count ? 'queued' : 'ignored', 'mappings' => $count], $count ? 202 : 200, $headers);
        } catch (SyncException $error) {
            return new JsonResponse(['error' => $error->reason], $error->status, $headers);
        }
    }
}
