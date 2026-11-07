<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The balance of one account in one currency at the end of a closed month. Insert-only.
 *
 * @property string $id
 * @property string $accounting_period_id
 * @property string $account_id
 * @property string $currency
 * @property string $total_debit
 * @property string $total_credit
 * @property string $balance
 */
class AccountingPeriodBalance extends Model
{
    use HasUuids;

    public $timestamps = false;
}
