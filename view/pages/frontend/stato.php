<?php

/**
 * Pagina di controllo: dice che il modulo è installato e che le sue rotte
 * frontend sono registrate. Sparisce con il piano 2, quando arrivano i layout
 * e le pagine vere.
 */

$gestionale = class_exists(\Wonder\Plugin\Gestionale\Gestionale::class);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Stato del modulo negozio</title>
</head>
<body>
    <h1>wonder-image/ecommerce risponde</h1>
    <p>Modulo gestionale disponibile: <?= $gestionale ? 'sì' : 'no' ?></p>
</body>
</html>
