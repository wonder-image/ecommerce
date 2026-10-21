<?php

namespace Wonder\Plugin\Ecommerce\Models;

use Wonder\App\Model;
use Wonder\Sql\TableSchema as Column;

/**
 * I contatori dei limiti a finestra fissa (Support\Throttle::attempt): una
 * riga per gruppo e chiave, la chiave è lo sha256 di «gruppo|chiave».
 */
class Throttle extends Model
{
    public static string $table = 'ecommerce_throttle';

    public static function tableSchema(): array
    {
        return [
            Column::key('throttle_key')->type('CHAR')->length(64)->null(false)->unique(),
            Column::key('hits')->int()->null(false)->default('0'),
            Column::key('window_start')->int()->null(false)->default('0'),
        ];
    }

    public static function dataSchema(): array
    {
        return [];
    }
}
