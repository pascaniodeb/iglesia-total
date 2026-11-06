<?php

namespace App\Modules\Accounting\Domain\Enums;

/**
 * The role a system account plays in automatic postings. Each function has exactly one account.
 */
enum SystemFunction: string
{
    /** CAJA: the money the church holds, per currency. */
    case Cash = 'cash';

    /** I-001 SALDO ANTERIOR: the balance brought from the books before this system. */
    case OpeningBalance = 'opening_balance';

    /** PUENTE CAMBIARIO: what a currency exchange goes through so each currency balances. */
    case CurrencyExchange = 'currency_exchange';
}
