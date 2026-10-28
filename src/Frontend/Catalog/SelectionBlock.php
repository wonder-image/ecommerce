<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

/**
 * Una selezione di Catalogo → Selezioni stampata col blocco scelto nel
 * gestionale, in qualunque punto del sito:
 *
 * ```php
 * <?=SelectionBlock::render('home-articoli')?>
 * ```
 *
 * - `default`: griglia di card ({@see ProductList});
 * - `swiper`: le stesse card in uno slider;
 * - `spotlight`: il primo articolo, foto 1:1 da un lato e card dall'altro,
 *   sopra lo sfondo della selezione.
 *
 * Un blocco che il modulo non conosce diventa la griglia. Con il blocco
 * arrivano `view_item_list` e `select_item` al clic su una card, con lo slug
 * come `item_list_id`. Senza articoli non stampa niente.
 */
final class SelectionBlock
{
    /** @param int|null $limit come in {@see ProductLists::selection()} */
    public static function render(string $slug, ?int $limit = null): string
    {
        $selection = ProductLists::selection($slug, $limit, CustomerPriceList::current());
        $cards = $selection['products'] ?? [];
        if ($cards === []) return '';

        if ($selection['block'] === 'spotlight') {
            $cards = [$cards[0]];
            $cardStyle = function_exists('module_asset') ? module_asset('ecommerce', 'css/card.css') : '';
            if ($cardStyle !== '') View::head('<link rel="stylesheet" href="'.e($cardStyle).'">');
            $html = View::component(Ecommerce::viewPath('components/catalog/selection/spotlight.php'), [
                'selection' => $selection,
                'card' => ProductCard::make($cards[0])->render('grid'),
            ]);
        } else {
            // Lo slider ne mostra al massimo `catalog.swiper.max`: l'evento conta quelle.
            if ($selection['block'] === 'swiper') {
                $cards = array_slice($cards, 0, max(1, (int) (((array) Ecommerce::config('catalog.swiper', []))['max'] ?? 12)));
            }
            $html = ProductList::make($cards)
                ->title($selection['title'], $selection['subtitle'], $selection['text'])
                ->render($selection['block'] === 'swiper' ? 'swiper' : 'grid');
        }
        if ($html === '') return '';

        $name = $selection['title'] !== '' ? $selection['title'] : $selection['name'];
        $event = DataLayer::itemListEvent($selection['slug'], $name, $cards, (string) Ecommerce::config('catalog.currency', 'EUR'));

        return View::component(Ecommerce::viewPath('components/catalog/selection/block.php'), [
            'html' => $html,
            'event' => $event,
        ]);
    }
}
