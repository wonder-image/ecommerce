<?php

namespace Wonder\Plugin\Ecommerce\Settings;

use Closure;
use Wonder\App\Models\Css\CssFont;
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
 * Un font è l'`id` di una riga visibile di `css_font`; vuoto (NULL) vuol dire
 * «come il sito», e ci torna da solo se la riga sparisce.
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
            $columns[] = Column::key('font_'.$area.'_id')->int()->foreign(CssFont::$table);
        }

        $columns[] = Column::key('checkout_guest')->enum(['true', 'false'])->default('false');

        return $columns;
    }

    public function data(): array
    {
        $data = [];

        foreach (array_keys(self::FONTS) as $area) {
            $data[] = Field::key('font_'.$area.'_id')->text()->sanitize(false);
        }

        $data[] = Field::key('checkout_guest')->text()->sanitize(false);

        return $data;
    }

    public function fields(): array
    {
        $options = ['' => 'Come il sito'];

        foreach ($this->fonts() as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        $fields = [];

        foreach (self::FONTS as $area => $label) {
            $fields[] = FormField::key('font_'.$area.'_id')->select($options)->label($label);
        }

        $fields[] = FormField::key('checkout_guest')->toggle()->value('false')->label('Ordini senza account');

        return $fields;
    }

    public function labels(): array
    {
        $labels = [];

        foreach (self::FONTS as $area => $label) {
            $labels['font_'.$area.'_id'] = $label;
        }

        return $labels + ['checkout_guest' => 'Ordini senza account'];
    }

    public function card(Closure $input): Card
    {
        return (new Card)->components([
            SectionTitle::make('Negozio online')
                ->tooltip('Il font delle pagine di accesso, account, checkout e carrello. «Come il sito» usa quello del tema.')
                ->columnSpan(12),
            $input('font_auth_id')->columnSpan(6),
            $input('font_account_id')->columnSpan(6),
            $input('font_checkout_id')->columnSpan(6),
            $input('font_cart_id')->columnSpan(6),
            SectionTitle::make('Ordini senza account')
                ->tooltip('Chi non ha un account ordina con la sola email: l\'account nasce senza password e l\'email dell\'ordine porta il link per sceglierla. Spento, il checkout chiede di accedere o registrarsi.')
                ->columnSpan(12),
            $input('checkout_guest')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** Un font si salva con l'id della sua riga; uno che non c'è torna «come il sito». */
    public function mutate(array $values): array
    {
        foreach (array_keys(self::FONTS) as $area) {
            $key = 'font_'.$area.'_id';

            if (array_key_exists($key, $values)) {
                $row = ShopFonts::find($values[$key], $this->fonts());
                $values[$key] = $row === null ? '' : (string) $row['id'];
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
