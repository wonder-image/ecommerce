<?php
/** php tests/CheckoutCompanyTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutCompany;
use Wonder\Plugin\Ecommerce\Frontend\Account\AccountCompany;
use Wonder\Plugin\Ecommerce\Frontend\Account\AccountOrder;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutForm;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;

// Etichette e testi: qui conta la chiave, la P. IVA arriva fra i parametri.
if (!function_exists('__t')) {
    function __t(string $key, array $params = []): string
    {
        return $key.($params === [] ? '' : ' '.implode(' ', $params));
    }
}

$options = [['id' => 4, 'name' => 'Rossi Srl', 'access' => 'buyer', 'company' => []]];
$azienda = [
    'id' => 4, 'business_name' => 'Rossi Srl', 'pi' => '01234567890', 'cf' => '01234567890', 'sdi' => 'ABC1234',
    'pec' => '', 'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100',
    'street' => 'Via Roma', 'number' => '1', 'more' => '', 'phone_prefix' => '+39', 'phone' => '0212345',
];
$persona = ['name' => 'Anna', 'surname' => 'Verdi'];

check('si compra solo per un\'azienda offerta: le altre valgono «per sé»', fn () =>
    CheckoutCompany::resolve(9, '4', $options) === 4
    && CheckoutCompany::resolve(9, '5', $options) === 0
    && CheckoutCompany::resolve(9, '4abc', $options) === 0
    && CheckoutCompany::resolve(9, ['4'], $options) === 0
    && CheckoutCompany::resolve(9, '0', $options) === 0);

check('per sé: azienda 0 e nessun referente, il resto del POST resta', function () {
    $post = CheckoutCompany::overlay(['billing_city' => 'Roma', 'invoice' => ''], null, []);

    return $post['company_id'] === 0 && $post['referent_name'] === '' && $post['billing_city'] === 'Roma' && $post['invoice'] === '';
});

check('con l\'azienda la fatturazione è la sua, anche i campi vuoti, col nome del referente', function () use ($azienda, $persona) {
    $post = CheckoutCompany::overlay([
        'billing_city' => 'Roma', 'billing_more' => 'scala B', 'billing_business_name' => 'Altra',
        'billing_name' => 'X', 'invoice' => '', 'billing_type' => 'private', 'same_as_shipping' => '1',
        'billing_phone' => '333',
    ], $azienda, $persona);

    return $post['company_id'] === 4 && $post['referent_name'] === 'Anna Verdi'
        && $post['billing_city'] === 'Milano' && $post['billing_more'] === ''
        && $post['billing_business_name'] === 'Rossi Srl' && $post['billing_pi'] === '01234567890'
        && $post['billing_name'] === 'Anna' && $post['billing_surname'] === 'Verdi'
        && $post['invoice'] === '1' && $post['billing_type'] === 'business' && $post['same_as_shipping'] === '0'
        && $post['billing_phone'] === '333'; // il cellulare resta quello dei contatti
});

check('senza la chiave company_id il POST non cambia (barra rapida, moduli vecchi)', fn () =>
    CheckoutCompany::apply(['billing_city' => 'Roma'], 9) === ['billing_city' => 'Roma']);

check('il riepilogo dell\'azienda sta in una riga', fn () =>
    str_contains(CheckoutCompany::summary($azienda), '01234567890')
    && str_contains(CheckoutCompany::summary($azienda), 'Via Roma 1, 20100 Milano (MI)'));

check('l\'ordine porta azienda e referente solo se il modulo li manda', function () {
    $con = CheckoutForm::data(['company_id' => '4', 'referent_name' => ' Anna Verdi ']);
    $per = CheckoutForm::data(['company_id' => '0', 'referent_name' => 'Anna']);
    $senza = CheckoutForm::data([]);

    return $con['company_id'] === 4 && $con['referent_name'] === 'Anna Verdi'
        && $per['company_id'] === 0 && $per['referent_name'] === ''
        && !array_key_exists('company_id', $senza);
});

check('fatturazione dell\'azienda incompleta: un solo errore, da completare nell\'account', function () {
    $billing = ['billing_name' => 'Anna', 'billing_surname' => 'Verdi', 'billing_type' => 'business', 'billing_business_name' => 'Rossi Srl'];
    $azienda = CheckoutRules::paymentErrors(['company_id' => 4, 'invoice' => '1'], $billing, []);
    $persona = CheckoutRules::paymentErrors(['company_id' => 0, 'invoice' => '1'], $billing, []);

    return $azienda === ['company_billing'] && in_array('billing', $persona, true) && in_array('invoice', $persona, true);
});

check('area account: ordini propri e delle aziende amministrate', function () {
    $sola = AccountOrder::condition(7);
    $conAziende = AccountOrder::condition(7, [3, '9', 0]);

    return str_contains($sola, '`customer_id` = 7 AND')
        && str_contains($conAziende, '(`customer_id` = 7 OR `company_id` IN (3, 9)) AND `status` NOT IN')
        && AccountOrder::visible(['customer_id' => 7, 'status' => 'pending'], 7)
        && AccountOrder::visible(['customer_id' => 8, 'company_id' => 3, 'status' => 'pending'], 7, [3])
        && !AccountOrder::visible(['customer_id' => 8, 'company_id' => 4, 'status' => 'pending'], 7, [3])
        && !AccountOrder::visible(['customer_id' => 7, 'status' => 'abandoned'], 7)
        && !AccountOrder::visible(['customer_id' => 0, 'status' => 'pending'], 0);
});

check('ordine di un\'azienda: colonne azienda e «ordinato da»', function () {
    $colonne = AccountOrder::buyerColumns(['company_id' => 3, 'billing_business_name' => 'Rossi Srl', 'referent_name' => 'Anna Verdi']);

    return AccountOrder::buyerColumns(['company_id' => 0]) === []
        && array_column($colonne, 'value') === ['Rossi Srl', 'Anna Verdi'];
});

check('«La mia azienda»: persone vive con ruolo e accesso', function () {
    $persone = AccountCompany::people([
        ['id' => 1, 'access' => 'admin', 'job_title' => 'Titolare', 'contact' => ['name' => 'Anna', 'surname' => 'Verdi']],
        ['id' => 2, 'access' => 'x', 'contact' => ['name' => 'Luca', 'surname' => 'Neri']],
        ['id' => 3, 'access' => 'buyer', 'deleted' => 'true', 'contact' => ['name' => 'Via']],
    ]);

    return count($persone) === 2
        && array_column($persone[0]['columns'], 'value') === ['Anna Verdi', 'Titolare', 'ecommerce.account.company.access_admin']
        && $persone[1]['columns'][2]['value'] === 'ecommerce.account.company.access_none';
});

summary();
