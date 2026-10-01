<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use InvalidArgumentException;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

/**
 * Collezione presentazionale di card prodotto con tre layout intercambiabili.
 */
final class ProductList
{
    /** @var list<ProductCard> */
    private array $cards = [];
    private string $view = 'grid';
    private ?string $title = null;
    private ?string $subtitle = null;
    private string $emptyMessage = 'Nessun prodotto disponibile';
    private int $desktopColumns = 4;
    private int $tabletColumns = 3;
    private int $phoneColumns = 2;
    private int $gap = 5;

    /** @var array<int, array<string, int|float>> */
    private array $swiperBreakpoints = [
        640 => ['slidesPerView' => 2, 'spaceBetween' => 16],
        1024 => ['slidesPerView' => 4, 'spaceBetween' => 24],
    ];
    private int|float $swiperSlidesPerView = 1.2;
    private int $swiperSpaceBetween = 12;
    private bool $swiperNavigation = true;
    private bool $swiperPagination = false;

    public function __construct(iterable $products = [])
    {
        $this->items($products);
    }

    public static function make(iterable $products = []): self
    {
        return new self($products);
    }

    public function items(iterable $products): static
    {
        $this->cards = [];

        foreach ($products as $product) {
            if ($product instanceof ProductCard) {
                $this->cards[] = $product;
                continue;
            }

            if (is_array($product) || is_object($product)) {
                $this->cards[] = ProductCard::make($product);
            }
        }

        return $this;
    }

    public function add(array|object $product): static
    {
        $this->cards[] = $product instanceof ProductCard ? $product : ProductCard::make($product);

        return $this;
    }

    public function view(string $view): static
    {
        $view = strtolower(trim($view));

        if (!in_array($view, ['grid', 'swiper', 'list'], true)) {
            throw new InvalidArgumentException("Vista elenco prodotti non valida: {$view}");
        }

        $this->view = $view;

        return $this;
    }

    public function title(?string $title, ?string $subtitle = null): static
    {
        $this->title = self::nullableText($title);
        $this->subtitle = self::nullableText($subtitle);

        return $this;
    }

    public function emptyMessage(string $message): static
    {
        $message = trim($message);
        $this->emptyMessage = $message !== '' ? $message : $this->emptyMessage;

        return $this;
    }

    public function columns(int $desktop = 4, int $tablet = 3, int $phone = 2): static
    {
        foreach (compact('desktop', 'tablet', 'phone') as $name => $columns) {
            if ($columns < 1 || $columns > 12) {
                throw new InvalidArgumentException("Il numero di colonne {$name} deve essere compreso tra 1 e 12.");
            }
        }

        $this->desktopColumns = $desktop;
        $this->tabletColumns = $tablet;
        $this->phoneColumns = $phone;

        return $this;
    }

    public function gap(int $gap): static
    {
        if ($gap < 0 || $gap > 12) {
            throw new InvalidArgumentException('La spaziatura deve essere compresa tra 0 e 12.');
        }

        $this->gap = $gap;

        return $this;
    }

    /** @param array<int|string, array<string, int|float>> $breakpoints */
    public function swiper(
        int|float $slidesPerView = 1.2,
        int $spaceBetween = 12,
        array $breakpoints = [],
        bool $navigation = true,
        bool $pagination = false,
    ): static {
        if ($slidesPerView <= 0 || $spaceBetween < 0) {
            throw new InvalidArgumentException('La configurazione Swiper richiede valori positivi.');
        }

        $this->swiperSlidesPerView = $slidesPerView;
        $this->swiperSpaceBetween = $spaceBetween;
        $this->swiperNavigation = $navigation;
        $this->swiperPagination = $pagination;

        if ($breakpoints !== []) {
            $this->swiperBreakpoints = $this->normalizeBreakpoints($breakpoints);
        }

        return $this;
    }

    /** @return list<int|string> */
    public function shownIds(): array
    {
        return array_values(array_filter(
            array_map(static fn (ProductCard $card): int|string|null => $card->id(), $this->cards),
            static fn (mixed $id): bool => $id !== null && $id !== ''
        ));
    }

    public function render(?string $view = null): string
    {
        if ($view !== null) {
            $this->view($view);
        }

        $header = View::component(Ecommerce::viewPath('components/catalog/product/header.php'), [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
        ]);

        if ($this->cards === []) {
            return $header.View::component(Ecommerce::viewPath('components/catalog/product/empty.php'), [
                'message' => $this->emptyMessage,
            ]);
        }

        $cardView = $this->view === 'list' ? 'list' : 'grid';
        $cards = array_map(
            static fn (ProductCard $card): string => $card->render($cardView),
            $this->cards
        );

        return $header.View::component(
            Ecommerce::viewPath('components/catalog/product/'.$this->view.'.php'),
            $this->viewData($cards)
        );
    }

    public function renderGrid(): string
    {
        return $this->render('grid');
    }

    public function renderSwiper(): string
    {
        return $this->render('swiper');
    }

    public function renderList(): string
    {
        return $this->render('list');
    }

    /** @param list<string> $cards */
    private function viewData(array $cards): array
    {
        return [
            'cards' => $cards,
            'grid_class' => sprintf(
                'd-grid col-%d col-t-%d col-p-%d gap-%d',
                $this->desktopColumns,
                $this->tabletColumns,
                $this->phoneColumns,
                $this->gap
            ),
            'swiper' => [
                'slides_per_view' => $this->swiperSlidesPerView,
                'space_between' => $this->swiperSpaceBetween,
                'breakpoints' => $this->swiperBreakpoints,
                'navigation' => $this->swiperNavigation,
                'pagination' => $this->swiperPagination,
            ],
        ];
    }

    /** @param array<int|string, array<string, int|float>> $breakpoints */
    private function normalizeBreakpoints(array $breakpoints): array
    {
        $normalized = [];

        foreach ($breakpoints as $width => $options) {
            if ((!is_int($width) && !ctype_digit((string) $width)) || (int) $width <= 0) {
                throw new InvalidArgumentException('Ogni breakpoint Swiper deve avere una larghezza positiva.');
            }

            $normalized[(int) $width] = $options;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private static function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
