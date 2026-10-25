<?php
declare(strict_types=1);

namespace Paywallex;

/**
 * SDK serveur officiel de l'API Paywallex /v1 (PHP 8.1+).
 * Côté serveur uniquement : ne mettez jamais une clé secrète pwx_sk_… dans un navigateur ou une app mobile.
 * Signé : Superagent (DV3, 24 oct. 2026).
 *
 * @property-read Service $checkoutSessions  create, retrieve
 * @property-read Service $payments          list, retrieve, all
 * @property-read Service $refunds           create, list, retrieve, all
 * @property-read Service $disputes          list, retrieve, all (justificatifs : addDisputeEvidence)
 * @property-read Service $payouts           list, retrieve
 * @property-read Service $events            list, retrieve
 * @property-read Service $customers         list, create, retrieve, update, action('archive')
 * @property-read Service $paymentLinks      … action('cancel')
 * @property-read Service $invoices          … action('issue'|'cancel')
 * @property-read Service $pots              … action('close'|'cancel')
 * @property-read Service $statements        list, retrieve
 * @property-read Service $plans             list, create, retrieve
 * @property-read Service $coupons           list, create, action('archive')
 * @property-read Service $subscriptions     create, list, retrieve, action('cancel'|'pause'|'resume'|'change_plan'|'manage_link')
 * @property-read Service $webhookEndpoints  list, create, action('rotate_secret') (suppression : deleteWebhookEndpoint)
 */
final class Paywallex
{
    public const VERSION = '1.1.0';

    private const SERVICES = [
        'checkoutSessions' => ['checkout_sessions', []], 'payments' => ['payments', []], 'refunds' => ['refunds', []],
        'disputes' => ['disputes', []], 'payouts' => ['payouts', []], 'events' => ['events', []],
        'customers' => ['customers', ['archive']], 'paymentLinks' => ['payment_links', ['cancel']], 'invoices' => ['invoices', ['issue', 'cancel']],
        'pots' => ['pots', ['close', 'cancel']], 'statements' => ['statements', []], 'plans' => ['plans', []], 'coupons' => ['coupons', ['archive']],
        'subscriptions' => ['subscriptions', ['cancel', 'pause', 'resume', 'change_plan', 'manage_link', 'usage']],
        // DV7 : Paywallex Elements (encadré sur votre site). Passez client_secret à elements.js.
        'elementSessions' => ['elements/sessions', ['cancel']],
        'webhookEndpoints' => ['webhook_endpoints', ['rotate_secret']],
    ];

    private string $baseUrl;
    /** @var array<string, Service> */
    private array $services = [];
    /** @var (callable(string, string, array<string, string>, ?string): array{int, array<string, string>, string})|null */
    private $transport;

    /**
     * @param array{base_url?: string, timeout?: int, max_retries?: int, language?: 'fr'|'en', transport?: callable} $options
     */
    public function __construct(private string $apiKey, array $options = [])
    {
        if ($apiKey === '') throw new PaywallexException('Clé manquante : passez votre clé secrète pwx_sk_… explicitement.', 0, 'configuration_error');
        $this->baseUrl = rtrim($options['base_url'] ?? 'https://api.paywallex.app', '/');
        $this->timeout = $options['timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 2;
        $this->language = $options['language'] ?? 'fr';
        $this->transport = $options['transport'] ?? null;
    }
    private int $timeout; private int $maxRetries; private string $language;

    public function __get(string $name): Service
    {
        if (!isset(self::SERVICES[$name])) throw new PaywallexException("Objet inconnu : {$name}.", 0, 'configuration_error');
        return $this->services[$name] ??= new Service($this, self::SERVICES[$name][0], self::SERVICES[$name][1]);
    }

    /** @return array<string, mixed> */
    public function account(): array { return $this->request('GET', '/v1/account'); }
    /** @return array<string, mixed> */
    public function balance(): array { return $this->request('GET', '/v1/balance'); }
    /** Réseaux Mobile Money du pays : masquez ceux qui sont « unavailable » avant le paiement. @return array<string, mixed> */
    public function networkStatus(): array { return $this->request('GET', '/v1/network_status'); }

    /** @param array{text: string, files?: string[]} $params @return array<string, mixed> */
    public function addDisputeEvidence(string $disputeId, array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v1/disputes/' . rawurlencode($disputeId) . '/evidence', $params, $idempotencyKey);
    }

    /** Dépose un justificatif PDF, PNG ou JPEG (5 Mo max) et renvoie son id. @return array<string, mixed> */
    public function uploadFile(string $name, string $contentType, string $bytes): array
    {
        $f = $this->request('POST', '/v1/files', ['name' => $name, 'content_type' => $contentType, 'size' => strlen($bytes)]);
        [$status] = $this->send('PUT', (string) $f['upload_url'], ['Content-Type' => $contentType], $bytes);
        if ($status < 200 || $status >= 300) throw new PaywallexException("Dépôt du fichier refusé ({$status}).", $status, 'file_upload_failed');
        return $f;
    }

    /**
     * DV7 : déclare une consommation (facturation à l'usage). 402 usage_cap_reached / usage_credits_exhausted si l'abonné doit agir.
     * @return array<string, mixed>
     */
    public function reportUsage(string $subscriptionId, int $quantity, ?string $description = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v1/subscriptions/' . rawurlencode($subscriptionId) . '/usage',
            ['quantity' => $quantity] + ($description !== null ? ['description' => $description] : []), $idempotencyKey);
    }

    /** DV7 : consommations déclarées et résumé (plafond, crédits restants). @param array<string, mixed> $params @return array<string, mixed> */
    public function usage(string $subscriptionId, array $params = []): array
    {
        return $this->request('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId) . '/usage', $params);
    }

    /** @return array<string, mixed> */
    public function deleteWebhookEndpoint(string $id): array { return $this->request('DELETE', '/v1/webhook_endpoints/' . rawurlencode($id)); }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $url = $this->baseUrl . $path;
        $body = null;
        if ($method === 'GET' && $params) {
            $q = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, array_filter($params, static fn ($v) => $v !== null));
            $url .= '?' . http_build_query($q);
        } elseif ($method !== 'GET') {
            $body = json_encode((object) $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        // Une seule clé d'idempotence pour toutes les tentatives : un POST rejoué n'est jamais doublé.
        $idem = $method === 'POST' ? ($idempotencyKey ?? self::uuid()) : null;
        $headers = ['Authorization' => "Bearer {$this->apiKey}", 'Accept-Language' => $this->language,
            'User-Agent' => 'paywallex-php/' . self::VERSION, 'Paywallex-Sdk' => 'php/' . self::VERSION]
            + ($body !== null ? ['Content-Type' => 'application/json'] : []) + ($idem ? ['Idempotency-Key' => $idem] : []);
        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $resHeaders, $text] = $this->send($method, $url, $headers, $body);
            } catch (\RuntimeException $e) {
                if ($attempt < $this->maxRetries) { usleep(500000 * 2 ** $attempt); continue; }
                throw new PaywallexException('Paywallex injoignable : ' . $e->getMessage(), 0, 'network_error');
            }
            $data = $text !== '' ? json_decode($text, true) : [];
            if (!is_array($data)) $data = [];
            if ($status >= 200 && $status < 300) return $data;
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                $ra = (int) ($resHeaders['retry-after'] ?? 0);
                usleep($ra > 0 ? min($ra, 30) * 1000000 : 500000 * 2 ** $attempt);
                continue;
            }
            $e = is_array($data['error'] ?? null) ? $data['error'] : [];
            throw new PaywallexException((string) ($e['message'] ?? "Requête refusée ({$status})."), $status, (string) ($e['code'] ?? 'unknown_error'),
                isset($e['param']) ? (string) $e['param'] : null, (string) ($e['request_id'] ?? ($resHeaders['request-id'] ?? '')) ?: null);
        }
    }

    /** @param array<string, string> $headers @return array{int, array<string, string>, string} */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport) return ($this->transport)($method, $url, $headers, $body);
        $ch = curl_init($url);
        $resHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $resHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $text = curl_exec($ch);
        if ($text === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException($err); }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $resHeaders, (string) $text];
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
