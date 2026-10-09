<?php
declare(strict_types=1);
require __DIR__.'/../harness.php';
$cookie = '';
$request = static function (string $path, ?array $post = null) use (&$cookie): array {
    $curl = curl_init((getenv('WI_TEST_URL') ?: 'https://ecommerce.test').$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    if ($cookie !== '') { curl_setopt($curl, CURLOPT_COOKIE, $cookie); }
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($curl);
    if (!is_string($response)) { throw new RuntimeException(curl_error($curl)); }
    $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', substr($response, 0, $headerLength), $match)) {
        $cookie = $match[1];
    }
    unset($curl);
    return [$status, substr($response, $headerLength)];
};
foreach (['login', 'signup/request', 'password/recovery', 'password/restore'] as $page) {
    [$status, $html] = $request('/account/auth/'.$page.'/');
    check('HTTP '.$page.' rende la pagina senza warning o fatal', fn () =>
        $status === 200 && !str_contains($html, '<b>Warning</b>') && !str_contains($html, '<b>Fatal error</b>')
        && substr_count($html, '<h1') === 1 && str_contains($html, 'NOINDEX,FOLLOW')
    );
}
[$status, $html] = $request('/account/auth/login/');
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $csrf);
[$status, $invalid] = $request('/account/auth/login/', ['csrf_token' => $csrf[1] ?? '', 'email' => 'not-an-email']);
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML($invalid);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($dom);
$selector = '//*[contains(concat(" ", normalize-space(@class), " "), " wi-alert ")]';
check('il server rifiuta captcha assente con un solo alert fuori dal form', fn () =>
    $status === 200 && $xpath->query($selector)->length === 1
    && $xpath->query('//form'.$selector)->length === 0
);
[$status] = $request('/account/auth/login/', ['csrf_token' => 'invalid']);
check('il POST con CSRF invalido viene rifiutato', fn () => $status === 419);
summary();
