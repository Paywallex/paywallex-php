<?php
// Tests du SDK PHP : sans réseau, puis bac à sable si PAYWALLEX_TEST_KEY est défini. php tests/run.php. Signé : Superagent.
declare(strict_types=1);
spl_autoload_register(static function (string $c): void { $f = __DIR__ . '/../src/' . substr(strrchr('\\' . $c, '\\'), 1) . '.php'; if (is_file($f)) require $f; });
use Paywallex\{Paywallex, PaywallexException, Webhook};

$pass = 0; $fail = 0; $skip = 0;
function check(string $name, callable $fn): void {
    global $pass, $fail, $skip;
    try { $r = $fn(); if ($r === 'skip') { $skip++; echo "- $name (sauté)\n"; return; } $pass++; echo "ok $name\n"; }
    catch (\Throwable $e) { $fail++; echo "ÉCHEC $name : " . $e->getMessage() . "\n"; }
}
function eq($a, $b, string $m = ''): void { if ($a !== $b) throw new \Exception("$m attendu " . var_export($b, true) . ', obtenu ' . var_export($a, true)); }
function throwsCode(callable $fn, string $code): PaywallexException { try { $fn(); } catch (PaywallexException $e) { eq($e->errorCode, $code); return $e; } throw new \Exception("pas d'erreur $code"); }
$sign = static fn (string $b, string $s, ?int $t = null) => 't=' . ($t ??= time()) . ',v1=' . hash_hmac('sha256', "$t.$b", $s);

check('webhook : valide, invalide, expirée, absente', function () use ($sign) {
    $b = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded']);
    eq(Webhook::constructEvent($b, $sign($b, 'k'), 'k')['type'], 'payment.succeeded');
    throwsCode(fn () => Webhook::constructEvent($b, $sign($b, 'autre'), 'k'), 'invalid_signature');
    throwsCode(fn () => Webhook::constructEvent($b, $sign($b, 'k', 1000), 'k'), 'signature_expired');
    throwsCode(fn () => Webhook::constructEvent($b, null, 'k'), 'invalid_signature');
});
check('POST : nouvelle tentative après 503, même clé d\'idempotence', function () {
    $keys = []; $n = 0;
    $t = function ($m, $u, $h, $b) use (&$keys, &$n) { $keys[] = $h['Idempotency-Key']; $n++;
        return $n === 1 ? [503, [], '{}'] : [201, [], json_encode(['id' => 'cs_1', 'url' => 'u', 'amount' => 1000])]; };
    $p = new Paywallex('pwx_sk_test_x', ['transport' => $t]);
    eq($p->checkoutSessions->create(['currency' => 'XOF', 'amount' => 1000])['id'], 'cs_1'); eq($n, 2); eq($keys[0], $keys[1]);
});
check('erreur /v1 : code, param, request_id', function () {
    $t = fn () => [400, [], json_encode(['error' => ['code' => 'parameter_invalid', 'message' => 'Invalid parameter: amount.', 'param' => 'amount', 'request_id' => 'req_1']])];
    $e = throwsCode(fn () => (new Paywallex('k', ['transport' => $t]))->refunds->create(['payment' => 'x']), 'parameter_invalid');
    eq($e->param, 'amount'); eq($e->requestId, 'req_1'); eq($e->status, 400);
});
check('clé obligatoire, en-têtes, adresse', function () {
    throwsCode(fn () => new Paywallex(''), 'configuration_error');
    $seen = null; $t = function ($m, $u, $h) use (&$seen) { $seen = [$m, $u, $h]; return [200, [], '{"object":"network_status","data":[]}']; };
    (new Paywallex('pwx_sk_test_x', ['transport' => $t, 'base_url' => 'https://api.paywallx.xyz/', 'language' => 'en']))->networkStatus();
    eq($seen[1], 'https://api.paywallx.xyz/v1/network_status'); eq($seen[2]['Authorization'], 'Bearer pwx_sk_test_x'); eq($seen[2]['Accept-Language'], 'en');
    eq(isset($seen[2]['Idempotency-Key']), false);
});
check('pagination : suit starting_after', function () {
    $urls = []; $t = function ($m, $u) use (&$urls) { $urls[] = $u;
        return [200, [], json_encode(str_contains($u, 'starting_after=p2') ? ['data' => [['id' => 'p3']], 'has_more' => false] : ['data' => [['id' => 'p1'], ['id' => 'p2']], 'has_more' => true])]; };
    $ids = []; foreach ((new Paywallex('k', ['transport' => $t]))->payments->all(['limit' => 2]) as $x) $ids[] = $x['id'];
    eq($ids, ['p1', 'p2', 'p3']); eq(count($urls), 2);
});
check('action inconnue refusée', function () { throwsCode(fn () => (new Paywallex('k'))->invoices->action('x', 'delete'), 'configuration_error'); });

$key = getenv('PAYWALLEX_TEST_KEY') ?: null; $base = getenv('PAYWALLEX_BASE_URL') ?: 'https://api.paywallex.app';
check('bac à sable : réseaux, compte, pagination, erreurs', function () use ($key, $base) {
    if (!$key) return 'skip';
    $p = new Paywallex($key, ['base_url' => $base]);
    eq($p->networkStatus()['object'], 'network_status'); eq(is_string($p->account()['id'] ?? null), true);
    $n = 0; foreach ($p->payments->all(['limit' => 2]) as $_) { if (++$n >= 3) break; }
    $e = throwsCode(fn () => $p->checkoutSessions->create(['currency' => 'XOF', 'amount' => 1000, 'line_items' => [['name' => 'x', 'unit_amount' => 500]]]), 'amount_mismatch');
    eq($e->param, 'amount');
    throwsCode(fn () => $p->disputes->retrieve('00000000-0000-4000-8000-000000000000'), 'resource_missing');
    $en = throwsCode(fn () => (new Paywallex($key, ['base_url' => $base, 'language' => 'en']))->disputes->retrieve('x'), 'resource_missing');
    eq($en->getMessage(), 'No such resource.');
});
check('bac à sable : session avec panier (compte non démo)', function () use ($key, $base) {
    if (!$key) return 'skip';
    try { $s = (new Paywallex($key, ['base_url' => $base]))->checkoutSessions->create(['currency' => 'XOF', 'line_items' => [['name' => 'Garba', 'unit_amount' => 1500, 'quantity' => 2]]]); }
    catch (PaywallexException $e) { if ($e->errorCode === 'review_demo_blocked') return 'skip'; throw $e; }
    eq($s['amount'], 3000);
});
echo "\n$pass réussis, $fail échoués, $skip sautés\n";
exit($fail ? 1 : 0);
