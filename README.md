# paywallex/paywallex-php

SDK serveur officiel de l'API Paywallex `/v1` pour PHP 8.1 ou plus récent. Il sert à encaisser par Orange Money, MTN MoMo, Moov Money, Wave ou le solde Paywallex, à rembourser, à suivre les litiges et les abonnements, et à vérifier les webhooks signés.

Seules les extensions `curl` et `json` sont nécessaires.

> Côté serveur uniquement. Ne mettez jamais une clé secrète `pwx_sk_…` dans une page web ou une application mobile.

## Installation

Installez le paquet :

```sh
composer require paywallex/paywallex-php
```

## Créer une session de paiement

Passez votre clé à la création du client. Une clé `pwx_sk_test_…` vise le bac à sable, une clé `pwx_sk_live_…` vise la production.

Créez une session, puis redirigez le client vers `url` :

```php
<?php
require 'vendor/autoload.php';

$paywallex = new Paywallex\Paywallex(getenv('PAYWALLEX_KEY'));

$session = $paywallex->checkoutSessions->create([
    'currency' => 'XOF',
    'line_items' => [
        ['name' => 'Attiéké poisson', 'unit_amount' => 2500, 'quantity' => 2],
        ['name' => 'Jus de bissap', 'unit_amount' => 500],
    ],
    'success_url' => 'https://maboutique.ci/merci',
    'metadata' => ['order' => 'CMD-1042'],
]);

header('Location: ' . $session['url']);
```

Les montants sont en XOF entiers : `2500` veut dire 2 500 F.

## Paywallex Elements (payer sur votre site)

Créez la session sur votre serveur, puis passez seulement `client_secret` à `elements.js` dans la page. Le client confirme sur son téléphone avec Face ID ; sans l'app, il paie dans une fenêtre Paywallex.

```php
$session = $paywallex->elementSessions->create([
    'mode' => 'subscription',
    'plan' => $planId,
    'allowed_origins' => ['https://boutique.ci'],
]);
// $session['client_secret'] (pwx_es_…) part dans la page, jamais votre clé pwx_sk_…
$paywallex->elementSessions->retrieve($session['id']); // status : succeeded
```

## Facturation à l'usage

```php
$paywallex->reportUsage($subscriptionId, 120, 'SMS envoyés', 'sms-2026-10-25');
$paywallex->usage($subscriptionId); // consommations et plafond
```

## Vérifier un webhook

Vérifiez la signature avant de traiter un événement. Le SDK compare en temps constant et refuse un horodatage de plus de 5 minutes.

Passez-lui le corps brut de la requête, pas un tableau déjà décodé :

```php
<?php
use Paywallex\{Webhook, PaywallexException};

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_PAYWALLEX_SIGNATURE'] ?? null,
        getenv('PAYWALLEX_WEBHOOK_SECRET'),
    );
    if ($event['type'] === 'checkout_session.completed') { /* livrer la commande */ }
    http_response_code(200);
} catch (PaywallexException $e) {
    http_response_code(400);
}
```

## Les erreurs

Chaque erreur est une `PaywallexException` avec un code stable. Testez le code, jamais le message :

```php
try {
    $paywallex->refunds->create(['payment' => $paymentId, 'amount' => 1000]);
} catch (Paywallex\PaywallexException $e) {
    if ($e->errorCode === 'refund_not_eligible') { /* … */ }
    error_log("$e->status $e->errorCode $e->param $e->requestId");
}
```

Passez `['language' => 'en']` en second argument du client pour recevoir les messages en anglais.

## Nouvelles tentatives et idempotence

Le SDK réessaie 2 fois en cas de coupure réseau, de `429` ou d'erreur `5xx`, en respectant `Retry-After`. Chaque `POST` garde la même clé d'idempotence d'une tentative à l'autre : un paiement ou un remboursement n'est jamais doublé.

Pour rejouer vous-même une requête sans risque, donnez votre propre clé :

```php
$paywallex->refunds->create(['payment' => $paymentId], 'remboursement-CMD-1042');
```

## Ce que couvre le SDK

| Objet | Méthodes |
| --- | --- |
| `checkoutSessions` | `create`, `retrieve` |
| `payments`, `refunds`, `disputes` | `list`, `retrieve`, `all` (toutes les pages) ; `refunds->create` |
| `payouts`, `events`, `statements` | `list`, `retrieve` |
| `customers`, `paymentLinks`, `invoices`, `pots`, `plans`, `coupons`, `subscriptions` | `list`, `create`, `retrieve`, `update`, `action` |
| `elementSessions` | `create`, `retrieve`, `action('cancel')` (Paywallex Elements) |
| `webhookEndpoints` | `list`, `create`, `action('rotate_secret')`, `deleteWebhookEndpoint()` |
| client | `account()`, `balance()`, `networkStatus()`, `uploadFile()`, `addDisputeEvidence()`, `reportUsage()`, `usage()` |

Accepter un litige (rembourser) ou le contester se fait dans le portail Paywallex, jamais par l'API. Un abonnement ou une hausse de prix est toujours accepté par le client dans Paywallex.

## Tests

Lancez les tests (le bac à sable est testé si `PAYWALLEX_TEST_KEY` est défini) :

```sh
php tests/run.php
```

## Licence

MIT.
