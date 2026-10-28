<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Elements\Media\Image;
use Wonder\Plugin\Ecommerce\Ecommerce;

/**
 * Il formato delle foto prodotto scelto nel config: card, scheda, miniature.
 * Valgono solo i formati della lib (`f-W-H`): `1:1`, `4:5`, `3:4`, `16:9`…
 * accettati anche come `4x5`, `4/5` o `4-5`. Un valore sconosciuto ricade
 * sul default, così il box non resta mai senza proporzioni. Il fit è
 * `contain` (la foto intera) o `cover` (riempie il box e si taglia).
 */
final class ImageFormat
{
    /** Le classi `f-W-H` di wonder-image/lib (`utilities/_formats.scss`). */
    public const FORMATS = [
        '1-1', '1-2', '2-1', '2-3', '3-2', '1-3', '3-1', '4-5', '5-4',
        '4-3', '3-4', '16-9', '9-16', '191-100', '100-191',
    ];

    /** Il formato come `W:H`, per lo swiper del core. */
    public static function ratio(string $key, string $default): string
    {
        return str_replace('-', ':', self::resolve($key, $default));
    }

    /** Il formato come valore CSS `aspect-ratio`, per i box a lato fisso come `.wi-thumb`. */
    public static function aspectRatio(string $key, string $default): string
    {
        return str_replace('-', ' / ', self::resolve($key, $default));
    }

    /** `cover` solo se il config lo dice: una foto prodotto di serie non si taglia. */
    public static function cover(string $key): bool
    {
        return Ecommerce::config($key, 'contain') === 'cover';
    }

    /** La foto col fit del config. */
    public static function fit(Image $image, string $key): Image
    {
        self::cover($key) ? $image->fitCover() : $image->fitContain();

        return $image;
    }

    /** La classe della lib per un box con quel formato: `f-4-5`. */
    public static function className(string $key, string $default): string
    {
        return 'f-'.self::resolve($key, $default);
    }

    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\s*(\d{1,3})\s*[:x\/-]\s*(\d{1,3})\s*$/i', $value, $matches) !== 1) {
            return null;
        }

        $format = (int) $matches[1].'-'.(int) $matches[2];

        return in_array($format, self::FORMATS, true) ? $format : null;
    }

    private static function resolve(string $key, string $default): string
    {
        return self::normalize(Ecommerce::config($key, $default))
            ?? self::normalize($default)
            ?? '1-1';
    }
}
