<?php
/** php tests/SlotsTest.php — slot delle pagine sigillate (docs/slots.md). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountPanel;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile;
use Wonder\Plugin\Ecommerce\Frontend\Slots;
use Wonder\View\View;

// e() e module_asset() del core.
require_once dirname((string) (new ReflectionClass(View::class))->getFileName(), 3).'/app/function/helper.php';

// Sito finto: partial degli slot e layout del core ridotti a un segnaposto.
$root = sys_get_temp_dir().'/ecommerce-slots-'.getmypid();
$files = [
    'custom/view/components/frontend/slots/banner.php' => '<aside data-slot="<?=e($slot)?>"><?=e($currency)?>:<?=count($items)?></aside>',
    'custom/view/components/frontend/slots/other.php' => '<p>altro</p>',
    'custom/view/components/frontend/slots/section.php' => '<p data-section="<?=e($section)?>"><?=e($user->name ?? "")?></p>',
    'custom/view/components/frontend/slots/surface.php' => '<p data-surface="<?=e($surface)?>"></p>',
    'custom/view/layout/frontend/account/panel.php' => '<div class="panel" data-title="<?=e($title ?? "")?>" data-panel="<?=get_class($account_panel)?>"><?=$PAGE_CONTENT?></div>',
    'custom/view/layout/frontend/account/auth.php' => '<div class="auth" data-title="<?=e($title ?? "")?>" data-profile="<?=get_class($auth_profile)?>"><?=$PAGE_CONTENT?></div>',
];
foreach ($files as $path => $content) {
    @mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, $content);
}
$GLOBALS['ROOT'] = $root;

function slotsConfig(array $slots): void
{
    Ecommerce::forgetConfig();
    $config = Ecommerce::config();
    $config['slots'] = $slots;
    (new ReflectionProperty(Ecommerce::class, 'config'))->setValue(null, $config);
}

function slotsRender(callable $view, array $data = []): string
{
    $file = tempnam(sys_get_temp_dir(), 'slot-view').'.php';
    file_put_contents($file, '<?php ($GLOBALS["__slotView"])($__data ?? []);');
    $GLOBALS['__slotView'] = $view;
    ob_start();
    try {
        (new View($file, $data))->render();
    } finally {
        $html = (string) ob_get_clean();
        @unlink($file);
    }
    return $html;
}

$cart = ['order' => ['total' => 10], 'items' => [['id' => 1], ['id' => 2]], 'currency' => 'EUR'];

check('slot configurato: la partial esce con il nome e i dati', function () use ($cart) {
    slotsConfig(['cart.before' => 'slots.banner']);
    return Slots::render('cart.before', $cart) === '<aside data-slot="cart.before">EUR:2</aside>';
});

check('slot con una lista: le partial escono nell\'ordine dato', function () use ($cart) {
    slotsConfig(['cart.after' => ['slots.other', 'slots.banner']]);
    return Slots::render('cart.after', $cart) === '<p>altro</p><aside data-slot="cart.after">EUR:2</aside>';
});

check('slot con percorso assoluto', function () use ($cart, $root) {
    slotsConfig(['cart.after_items' => $root.'/custom/view/components/frontend/slots/other.php']);
    return Slots::render('cart.after_items', $cart) === '<p>altro</p>';
});

check('slot non configurato o vuoto: nessun output', function () use ($cart) {
    slotsConfig(['cart.after' => '', 'checkout.after' => []]);
    return Slots::render('cart.before', $cart) === ''
        && Slots::render('cart.after', $cart) === ''
        && Slots::render('checkout.after', $cart + ['guest' => true]) === '';
});

check('partial mancante: errore chiaro con il nome dello slot', function () use ($cart) {
    slotsConfig(['cart.before' => 'slots.missing']);
    try {
        Slots::render('cart.before', $cart);
    } catch (RuntimeException $exception) {
        return str_contains($exception->getMessage(), 'Slot ecommerce cart.before')
            && str_contains($exception->getMessage(), 'slots.missing');
    }
    return false;
});

check('nome di slot sconosciuto in una view: LogicException', function () {
    slotsConfig([]);
    try {
        Slots::render('cart.banner');
    } catch (LogicException) {
        return true;
    }
    return false;
});

check('area cliente: account.before e account.after attorno al contenuto, nel pannello del core', function () {
    slotsConfig(['account.before' => 'slots.section', 'account.after' => 'slots.other']);
    $panel = new EcommerceAccountPanel();
    $html = slotsRender(function () use ($panel) {
        $panel->layout(['title' => 'Ordini', 'active' => 'orders', 'user' => (object) ['name' => 'Ada']]);
        echo '<main-content/>';
        View::end();
    });
    return $html === '<div class="panel" data-title="Ordini" data-panel="'.EcommerceAccountPanel::class.'">'
        .'<p data-section="orders">Ada</p><main-content/><p>altro</p></div>';
});

check('area cliente senza slot: solo il contenuto', function () {
    slotsConfig([]);
    $html = slotsRender(function () {
        (new EcommerceAccountPanel())->layout(['title' => 'Dati', 'active' => 'personal']);
        echo '<main-content/>';
        View::end();
    });
    return $html === '<div class="panel" data-title="Dati" data-panel="'.EcommerceAccountPanel::class.'"><main-content/></div>';
});

check('auth: auth.before e auth.after con la pagina, nel layout auth del core', function () {
    slotsConfig(['auth.before' => 'slots.surface', 'auth.after' => 'slots.other']);
    $html = slotsRender(function () {
        (new EcommerceAuthProfile())->layout(['title' => 'Accedi']);
        echo '<form id="login"></form>';
        View::end();
    }, ['surface' => 'login']);
    return str_contains($html, '<div class="auth" data-title="Accedi" data-profile="'.EcommerceAuthProfile::class.'">'
        .'<p data-surface="login"></p><form id="login"></form><p>altro</p></div>');
});

check('il pannello di default è EcommerceAccountPanel', function () {
    Ecommerce::forgetConfig();
    return Ecommerce::config('account.panel') === EcommerceAccountPanel::class;
});

check('ogni slot è usato una volta nelle view e documentato in docs/slots.md', function () {
    $views = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../view', FilesystemIterator::SKIP_DOTS)) as $file) {
        $views .= file_get_contents((string) $file);
    }
    preg_match_all("/Slots::render\\('([^']+)'/", $views, $used);
    $docs = (string) file_get_contents(__DIR__.'/../docs/slots.md');
    preg_match_all('/^\| `([a-z_.]+)` \|/m', $docs, $documented);
    $names = array_keys(Slots::NAMES);
    $sorted = static function (array $list): array { sort($list); return $list; };

    return $sorted($used[1]) === $sorted($names)
        && $sorted($documented[1]) === $sorted($names);
});

Ecommerce::forgetConfig();
foreach (array_reverse(array_keys($files)) as $path) {
    @unlink($root.'/'.$path);
}
