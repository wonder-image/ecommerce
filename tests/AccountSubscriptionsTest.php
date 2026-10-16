<?php
/** php tests/AccountSubscriptionsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Account\AccountSubscriptionItems;

$root = dirname(__DIR__);

// Abbonamento 10: hosting (piano 1) con due domini (piano 2, figli), un'email
// (piano 3) senza madre e una riga che termina. Il 20 è di un altro abbonamento.
$righe = [
    ['id' => 1, 'subscription_id' => 10, 'plan_price_id' => 101, 'parent_item_id' => 0, 'status' => 'active'],
    ['id' => 2, 'subscription_id' => 10, 'plan_price_id' => 201, 'parent_item_id' => 1, 'status' => 'active'],
    ['id' => 3, 'subscription_id' => 10, 'plan_price_id' => 201, 'parent_item_id' => 1, 'status' => 'pending'],
    ['id' => 4, 'subscription_id' => 10, 'plan_price_id' => 301, 'parent_item_id' => 0, 'status' => 'active'],
    ['id' => 5, 'subscription_id' => 10, 'plan_price_id' => 302, 'parent_item_id' => 0, 'status' => 'ending'],
];
$piani = [101 => 1, 102 => 1, 103 => 5, 201 => 2, 202 => 2, 301 => 3, 302 => 3, 401 => 4];
// Il dominio (2) sta sotto l'hosting (1) o sotto il server (5); il 4 sotto l'email.
$madri = static fn (int $plan): array => [2 => [1, 5], 4 => [3]][$plan] ?? [];

check('la riga è del cliente solo se è del suo abbonamento e si può ancora toccare', function () use ($righe) {
    return AccountSubscriptionItems::belongs($righe[0], 10)
        && AccountSubscriptionItems::belongs($righe[2], 10)
        && !AccountSubscriptionItems::belongs($righe[0], 20)
        && !AccountSubscriptionItems::belongs($righe[4], 10)
        && !AccountSubscriptionItems::belongs(['id' => 9, 'subscription_id' => 10, 'status' => 'ended'], 10)
        && !AccountSubscriptionItems::belongs(['id' => 9, 'subscription_id' => 10, 'status' => 'active', 'deleted' => 'true'], 10)
        && !AccountSubscriptionItems::belongs(null, 10)
        && !AccountSubscriptionItems::belongs(['id' => 0, 'subscription_id' => 0, 'status' => 'active'], 0);
});

check('le figlie di una riga: quelle in corso che si appoggiano a lei', function () use ($righe) {
    $figlie = AccountSubscriptionItems::children($righe, 1);
    $conChiusa = [...$righe, ['id' => 6, 'subscription_id' => 10, 'plan_price_id' => 201, 'parent_item_id' => 1, 'status' => 'ended']];

    return array_column($figlie, 'id') === [2, 3]
        && AccountSubscriptionItems::children($righe, 4) === []
        && array_column(AccountSubscriptionItems::children($conChiusa, 1), 'id') === [2, 3];
});

check('si toglie una riga solo se ne resta un\'altra in corso: l\'ultima è la disdetta', function () use ($righe) {
    $soloHosting = [$righe[0], $righe[1], $righe[4]];

    return AccountSubscriptionItems::removable($righe, 1)
        && AccountSubscriptionItems::removable($righe, 2)
        && AccountSubscriptionItems::removable($righe, 4)
        && !AccountSubscriptionItems::removable($soloHosting, 1)
        && AccountSubscriptionItems::removable($soloHosting, 2)
        && !AccountSubscriptionItems::removable($righe, 5)
        && !AccountSubscriptionItems::removable($righe, 99);
});

check('le madri possibili: righe in corso senza madre di uno dei piani chiesti', function () use ($righe, $piani) {
    $perDominio = AccountSubscriptionItems::parents($righe, [1, 5], $piani);
    $perAltro = AccountSubscriptionItems::parents($righe, [3], $piani);

    return array_column($perDominio, 'id') === [1]
        && array_column($perAltro, 'id') === [4]
        && AccountSubscriptionItems::parents($righe, [], $piani) === []
        && AccountSubscriptionItems::parents($righe, [2], $piani) === [];
});

check('i prezzi che si possono aggiungere: fuori quelli che vogliono una madre che non c\'è', function () use ($righe, $piani, $madri) {
    $candidati = [
        ['code' => 'ppr_host2', 'price_id' => 102, 'plan_id' => 1, 'label' => 'Hosting annuale'],
        ['code' => 'ppr_dom', 'price_id' => 202, 'plan_id' => 2, 'label' => 'Dominio'],
        ['code' => 'ppr_x', 'price_id' => 401, 'plan_id' => 4, 'label' => 'Casella in più'],
    ];
    $senzaEmail = array_values(array_filter($righe, static fn (array $r): bool => $r['id'] !== 4));

    return array_keys(AccountSubscriptionItems::addable($candidati, $righe, $piani, $madri)) === ['ppr_host2', 'ppr_dom', 'ppr_x']
        && array_keys(AccountSubscriptionItems::addable($candidati, $senzaEmail, $piani, $madri)) === ['ppr_host2', 'ppr_dom']
        && AccountSubscriptionItems::addable($candidati, $righe, $piani, $madri)['ppr_dom'] === 'Dominio';
});

check('cambia: fuori il prezzo attuale e i piani che rompono madre o figlie', function () use ($righe, $piani, $madri) {
    $candidati = [
        ['code' => 'ppr_host1', 'price_id' => 101, 'plan_id' => 1, 'label' => 'Hosting mensile'],
        ['code' => 'ppr_host2', 'price_id' => 102, 'plan_id' => 1, 'label' => 'Hosting annuale'],
        ['code' => 'ppr_srv', 'price_id' => 103, 'plan_id' => 5, 'label' => 'Server'],
        ['code' => 'ppr_dom1', 'price_id' => 201, 'plan_id' => 2, 'label' => 'Dominio'],
        ['code' => 'ppr_dom2', 'price_id' => 202, 'plan_id' => 2, 'label' => 'Dominio biennale'],
        ['code' => 'ppr_mail', 'price_id' => 301, 'plan_id' => 3, 'label' => 'Email'],
        ['code' => 'ppr_x', 'price_id' => 401, 'plan_id' => 4, 'label' => 'Casella in più'],
    ];

    // L'hosting ha figli dominio: va bene solo un piano senza madri sotto cui il dominio può stare.
    $hosting = array_keys(AccountSubscriptionItems::changeTargets($righe[0], $righe, $candidati, $piani, $madri));
    // Il dominio ha madre hosting: solo piani che stanno sotto l'hosting.
    $dominio = array_keys(AccountSubscriptionItems::changeTargets($righe[1], $righe, $candidati, $piani, $madri));
    // L'email non ha madre né figlie: solo piani senza madri.
    $email = array_keys(AccountSubscriptionItems::changeTargets($righe[3], $righe, $candidati, $piani, $madri));

    return $hosting === ['ppr_host2', 'ppr_srv']
        && $dominio === ['ppr_dom2']
        && $email === ['ppr_host1', 'ppr_host2', 'ppr_srv'];
});

check('il messaggio di conferma di Togli nomina le figlie quando ci sono', function () {
    // Il traduttore vero è `__t`; qui uno che mostra chiave e valori.
    $t = static fn (string $key, array $values): string => $key.' '.implode(' | ', $values);
    $conFiglie = AccountSubscriptionItems::removeConfirm('Hosting', ['dominio-a.it', 'dominio-b.it'], $t);
    $senza = AccountSubscriptionItems::removeConfirm('Email', [], $t);

    return str_contains($conFiglie, 'remove_confirm_children') && str_contains($conFiglie, 'Hosting')
        && str_contains($conFiglie, 'dominio-a.it, dominio-b.it')
        && str_contains($senza, 'remove_confirm ') && str_contains($senza, 'Email') && !str_contains($senza, 'dominio');
});

check('rotte dell\'area cliente: aggiungi, anteprima, conferma, togli e cambia per riga', function () use ($root) {
    $extension = (string) file_get_contents($root.'/src/Frontend/Account/EcommerceAccountExtension.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Account/EcommerceAccountController.php');

    foreach (['subscriptions.add', 'subscriptions.add.preview', 'subscriptions.add.confirm', 'subscriptions.item.remove', 'subscriptions.item.change'] as $name) {
        if (!str_contains($extension, "->name('".$name."')") || !str_contains($controller, "'".$name."' =>")) {
            return false;
        }
    }

    return str_contains($extension, "'/abbonamenti/{code}/aggiungi/'")
        && str_contains($extension, "'/abbonamenti/{code}/aggiungi/conferma/'")
        && str_contains($extension, "'/abbonamenti/{code}/righe/{item}/togli/'")
        && str_contains($extension, "'/abbonamenti/{code}/righe/{item}/cambia/'")
        && !str_contains($extension, "->name('subscriptions.change')")
        && !str_contains($controller, 'changePlan(')
        && str_contains($controller, "SubscriptionItems::add(")
        && str_contains($controller, "'prorate'")
        && str_contains($controller, "'period_end'")
        && str_contains($controller, 'AccountSubscriptionItems::belongs(');
});

check('la pagina degli abbonamenti: campi con FormField, conferme del lib, niente confirm() a mano', function () use ($root) {
    $view = (string) file_get_contents($root.'/view/pages/account/subscriptions.php');
    $add = (string) file_get_contents($root.'/view/pages/account/subscription-add.php');

    return !str_contains($view, 'onsubmit=') && !str_contains($view, '<select')
        && str_contains($view, 'Button::post(') && str_contains($view, '->confirm(')
        && str_contains($view, 'id="subscription_change_') && str_contains($view, 'id="subscription_pick_')
        && str_contains($add, 'id="subscription_add"') && str_contains($add, 'id="subscription_add_confirm"')
        && !str_contains($add, '<select') && !str_contains($add, '<h1')
        && str_contains($add, '$account_panel->layout(');
});

check('la pagina dei piani al limite rimanda ad Aggiungi servizio dell\'area cliente', function () use ($root) {
    $source = (string) file_get_contents($root.'/src/Frontend/Subscriptions/SubscriptionsController.php');

    return str_contains($source, 'public static function configureFields(')
        && str_contains($source, "'account.subscriptions.add'");
});

check('lingua: chiavi nuove in it ed en, la nota del cambio non parla più di «senza conguagli»', function () use ($root) {
    $keys = ['items', 'quantity', 'item_ends', 'item_starts', 'item_scheduled', 'add', 'add_label', 'add_submit', 'add_title', 'add_none',
        'add_preview', 'add_confirm', 'add_quote', 'add_trial', 'add_edit', 'added', 'remove', 'remove_confirm', 'remove_confirm_children',
        'removed', 'removed_children', 'change_label', 'change', 'change_note', 'changed', 'not_editable', 'add_parent_missing'];

    foreach (['it', 'en'] as $locale) {
        $lang = json_decode((string) file_get_contents($root.'/lang/'.$locale.'/ecommerce.json'), true);
        $section = $lang['account']['subscriptions'] ?? [];

        foreach ($keys as $key) {
            if (!is_string($section[$key] ?? null) || trim($section[$key]) === '') {
                echo "  manca {$locale}.account.subscriptions.{$key}\n";

                return false;
            }
        }

        if (str_contains(strtolower($section['change_note'].$section['changed']), $locale === 'it' ? 'senza conguagli' : 'no proration')) {
            return false;
        }
    }

    return true;
});

summary();
