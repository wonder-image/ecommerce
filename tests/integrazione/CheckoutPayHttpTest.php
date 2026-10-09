<?php
/** php tests/integrazione/CheckoutPayHttpTest.php */
declare(strict_types=1);
require __DIR__.'/../harness.php';

$cookie = '';
/** @return array{0: int, 1: string, 2: string} stato, intestazioni, corpo */
$request = static function (string $path, ?array $post = null, array $headers = []) use (&$cookie): array {
    $curl = curl_init('https://ecommerce.test'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => $headers]);
    if ($cookie !== '') { curl_setopt($curl, CURLOPT_COOKIE, $cookie); }
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($curl);
    if (!is_string($response)) { throw new RuntimeException(curl_error($curl)); }
    $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $head = substr($response, 0, $headerLength);
    if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $head, $match)) {
        $cookie = $match[1];
    }
    unset($curl);
    return [$status, $head, substr($response, $headerLength)];
};

[$status] = $request('/checkout/return/?payment_intent=pi_estraneo');
check('il ritorno senza un pagamento in sessione è un 404', fn () => $status === 404);

[$status, $head] = $request('/checkout/pay/');
check('la pagina di pagamento senza ordine in sessione rimanda al carrello', fn () =>
    $status === 302 && preg_match('#^Location:\s*\S*/cart/\s*$#mi', $head) === 1);

[$status] = $request('/checkout/abandon/', ['csrf_token' => 'invalid']);
check('l\'abbandono con CSRF invalido viene rifiutato', fn () => $status === 419);

summary();
