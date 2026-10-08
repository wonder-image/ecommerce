<?php

namespace Wonder\Plugin\Ecommerce\Settings;

use Closure;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Data\UploadSchema as Field;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Ecommerce\Support\ShopFonts;
use Wonder\Plugin\Gestionale\Extensions\SettingsSection;
use Wonder\Sql\TableSchema as Column;

/**
 * Il riquadro «Negozio online» nelle Impostazioni di Set Up: il font di
 * accesso, account, checkout e carrello e gli ordini senza account.
 *
 * Un font è il `name` di una riga visibile di `css_font`; vuoto vuol dire
 * «come il sito». Rinominare la riga riporta la scelta al font del sito.
 */
final class OnlineShopSettings extends SettingsSection
{
    /** Area => etichetta, nell'ordine del riquadro. */
    private const FONTS = [
        'auth' => 'Font accesso',
        'account' => 'Font account',
        'checkout' => 'Font checkout',
        'cart' => 'Font carrello',
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $fonts;

    /** @param list<array<string, mixed>>|null $fonts le righe di `css_font` (null: quelle del sito) */
    public function __construct(?array $fonts = null)
    {
        $this->fonts = $fonts;
    }

    public function columns(): array
    {
        $columns = [];

        foreach (array_keys(self::FONTS) as $area) {
            $column = Column::key('font_'.$area)->length(40);
            $columns[] = $area === 'checkout' ? $column->default('Inter') : $column;
        }

        $columns[] = Column::key('checkout_guest')->enum(['true', 'false'])->default('false');

        return $columns;
    }

    public function data(): array
    {
        $data = [];

        foreach (array_keys(self::FONTS) as $area) {
            $data[] = Field::key('font_'.$area)->text()->sanitize(false);
        }

        $data[] = Field::key('checkout_guest')->text()->sanitize(false);

        return $data;
    }

    public function fields(): array
    {
        $options = ['' => 'Come il sito'];

        foreach ($this->fonts() as $row) {
            $name = (string) ($row['name'] ?? '');
            $options[$name] = $name;
        }

        $fields = [];

        foreach (self::FONTS as $area => $label) {
            $fields[] = FormField::key('font_'.$area)->select($options)->label($label);
        }

        $fields[] = FormField::key('checkout_guest')->toggle()->value('false')->label('Ordini senza account');

        return $fields;
    }

    public function labels(): array
    {
        $labels = [];

        foreach (self::FONTS as $area => $label) {
            $labels['font_'.$area] = $label;
        }

        return $labels + ['checkout_guest' => 'Ordini senza account'];
    }

    public function card(Closure $input): Card
    {
        return (new Card)->components([
            SectionTitle::make('Negozio online')
                ->tooltip('Il font delle pagine di accesso, account, checkout e carrello. «Come il sito» usa quello del tema.')
                ->columnSpan(12),
            $input('font_auth')->columnSpan(6),
            $input('font_account')->columnSpan(6),
            $input('font_checkout')->columnSpan(6),
            $input('font_cart')->columnSpan(6),
            SectionTitle::make('Ordini senza account')
                ->tooltip('Chi non ha un account ordina con la sola email: l\'account nasce senza password e l\'email dell\'ordine porta il link per sceglierla. Spento, il checkout chiede di accedere o registrarsi.')
                ->columnSpan(12),
            $input('checkout_guest')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** Un font si salva col nome della sua riga; uno che non c'è torna «come il sito». */
    public function mutate(array $values): array
    {
        foreach (array_keys(self::FONTS) as $area) {
            if (array_key_exists('font_'.$area, $values)) {
                $row = ShopFonts::find((string) $values['font_'.$area], $this->fonts());
                $values['font_'.$area] = $row === null ? '' : (string) $row['name'];
            }
        }

        if (array_key_exists('checkout_guest', $values)) {
            $values['checkout_guest'] = (string) $values['checkout_guest'] === 'true' ? 'true' : 'false';
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function fonts(): array
    {
        return $this->fonts ??= ShopFonts::visible();
    }
}
