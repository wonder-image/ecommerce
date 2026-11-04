<?php
/** php tests/SubscriptionPriceLabelTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;

// I testi veri del modulo, in italiano: «{{price}}/Anno», «Da {{price}}».
if (!function_exists('__t')) {
    function __t(string $key, array $replace = []): string
    {
        $text = json_decode((string) file_get_contents(__DIR__.'/../lang/it/ecommerce.json'), true);

        foreach (array_slice(explode('.', $key), 1) as $part) {
            $text = is_array($text) ? ($text[$part] ?? null) : null;
        }

        if (!is_string($text)) {
            return $key;
        }

        foreach ($replace as $name => $value) {
            $text = str_replace('{{'.$name.'}}', (string) $value, $text);
        }

        return $text;
    }
}

$annuale = ['code' => 'ppr_anno', 'amount' => 30.0, 'regular' => 36.0, 'unit' => 'year', 'count' => 1];
$mensile = ['code' => 'ppr_mese', 'amount' => 3.0, 'regular' => null, 'unit' => 'month', 'count' => 1];
$trimestrale = ['code' => 'ppr_tre', 'amount' => 8.5, 'regular' => null, 'unit' => 'month', 'count' => 3];

check('il prezzo col periodo: €/Anno, €/Mese, €/3 Mesi e il pieno barrato', function () use ($annuale, $mensile, $trimestrale): bool {
    return SubscriptionView::pricePer($annuale, 'EUR') === '30,00 €/Anno'
        && SubscriptionView::pricePer($annuale, 'EUR', 'product', true) === '36,00 €/Anno'
        && SubscriptionView::pricePer($mensile, 'EUR') === '3,00 €/Mese'
        && SubscriptionView::pricePer($mensile, 'EUR', 'product', true) === null
        && SubscriptionView::pricePer($trimestrale, 'EUR') === '8,50 €/3 Mesi';
});

check('il prezzo scelto: quello del codice, se no il primo', function () use ($annuale, $mensile): bool {
    return SubscriptionView::chosen([$annuale, $mensile], 'ppr_mese') === $mensile
        && SubscriptionView::chosen([$annuale, $mensile], '') === $annuale
        && SubscriptionView::chosen([$annuale, $mensile], 'ppr_altro') === $annuale
        && SubscriptionView::chosen([], 'ppr_mese') === null;
});

check('la card: «Da» solo con più prezzi', function (): bool {
    return SubscriptionView::from('3,00 €/Mese', 2) === 'Da 3,00 €/Mese'
        && SubscriptionView::from('3,00 €/Mese', 1) === '3,00 €/Mese';
});

check('le etichette del periodo e «Da» sono tradotte in it ed en', function (): bool {
    $ok = true;
    foreach (['it', 'en'] as $lingua) {
        $json = json_decode((string) file_get_contents(__DIR__.'/../lang/'.$lingua.'/ecommerce.json'), true);
        foreach (['month', 'months', 'year', 'years'] as $unita) {
            $ok = $ok && str_contains((string) ($json['subscriptions']['per'][$unita] ?? ''), '{{price}}');
        }
        $ok = $ok && str_contains((string) ($json['subscriptions']['from'] ?? ''), '{{price}}');
    }

    return $ok;
});

summary();
