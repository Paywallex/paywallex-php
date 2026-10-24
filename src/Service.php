<?php
declare(strict_types=1);

namespace Paywallex;

/** Un objet de l'API (/v1/<chemin>) : list, create, retrieve, update, all, action. Signé : Superagent. */
final class Service
{
    /** @param string[] $actions */
    public function __construct(private Paywallex $client, private string $path, private array $actions = []) {}

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params = []): array { return $this->client->request('GET', "/v1/{$this->path}", $params); }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function create(array $params, ?string $idempotencyKey = null): array { return $this->client->request('POST', "/v1/{$this->path}", $params, $idempotencyKey); }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array { return $this->client->request('GET', "/v1/{$this->path}/" . rawurlencode($id)); }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function update(string $id, array $params, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', "/v1/{$this->path}/" . rawurlencode($id), $params, $idempotencyKey);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function action(string $id, string $action, array $params = [], ?string $idempotencyKey = null): array
    {
        if (!in_array($action, $this->actions, true)) {
            throw new PaywallexException("Action inconnue : {$action}. Possibles : " . implode(', ', $this->actions) . '.', 0, 'configuration_error');
        }
        return $this->client->request('POST', "/v1/{$this->path}/" . rawurlencode($id) . "/{$action}", $params, $idempotencyKey);
    }

    /** Parcourt toutes les pages. @param array<string, mixed> $params @return \Generator<int, array<string, mixed>> */
    public function all(array $params = []): \Generator
    {
        $after = null;
        for ($page = 0; $page < 10000; $page++) {
            $list = $this->list($after ? $params + ['starting_after' => $after] : $params);
            $data = $list['data'] ?? [];
            foreach ($data as $item) yield $item;
            if (empty($list['has_more']) || !$data) return;
            $after = end($data)['id'];
        }
    }
}
