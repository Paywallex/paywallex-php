<?php
declare(strict_types=1);

namespace Paywallex;

/** Vérification de l'en-tête Paywallex-Signature (t=…,v1=…). Signé : Superagent. */
final class Webhook
{
    /**
     * Vérifie la signature et renvoie l'événement décodé.
     * Passez le corps BRUT (file_get_contents('php://input')), jamais un tableau déjà décodé.
     * @return array<string, mixed>
     */
    public static function constructEvent(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300): array
    {
        if (!preg_match('/^t=([0-9]+),v1=([a-f0-9]{64})$/', trim((string) $signatureHeader), $m)) {
            throw new PaywallexException('Signature absente ou mal formée.', 400, 'invalid_signature');
        }
        if (abs(time() - (int) $m[1]) > $toleranceSeconds) {
            throw new PaywallexException('Signature expirée.', 400, 'signature_expired');
        }
        $expected = hash_hmac('sha256', $m[1] . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $m[2])) {
            throw new PaywallexException('Signature invalide.', 400, 'invalid_signature');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) throw new PaywallexException('Corps illisible.', 400, 'invalid_body');
        return $event;
    }
}
