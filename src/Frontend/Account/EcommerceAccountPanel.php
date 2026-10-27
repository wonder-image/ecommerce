<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Plugin\Ecommerce\Ecommerce;

/** Pannello account del negozio: aggiunge gli slot `account.*`. Il sito che lo sostituisce lo estende. */
class EcommerceAccountPanel extends AccountPanel
{
    public function layout(array $data = []): void
    {
        Ecommerce::layout('account', ['panel_layout' => $data + ['account_panel' => $this]]);
    }
}
