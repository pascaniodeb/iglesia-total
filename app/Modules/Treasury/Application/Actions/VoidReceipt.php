<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use App\Modules\Treasury\Infrastructure\Models\ReceiptVoid;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a receipt: it keeps its number and its content, gets a reason, and the entry it posted is
 * undone by a reversal (dated like the receipt while its month is open, today once it is closed).
 *
 * Voiding a receipt of income whose money was already spent is refused, as voiding any income is.
 * Nothing is edited or deleted: to replace a wrong receipt, issue a new one.
 */
final readonly class VoidReceipt
{
    public function __construct(
        private CashMovements $movements,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Receipt $receipt, string $reason): ReceiptVoid
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Write why the receipt is voided (between 5 and 500 characters).')]);
        }

        return DB::transaction(function () use ($receipt, $reason): ReceiptVoid {
            Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (ReceiptVoid::query()->where('receipt_id', $receipt->id)->exists()) {
                throw ValidationException::withMessages(['receipt' => __('This receipt was already voided.')]);
            }

            try {
                $reversal = $this->movements->reverse($receipt->journal_entry_id, $reason);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();

                throw ValidationException::withMessages(isset($errors['entry']) ? ['receipt' => $errors['entry']] : $errors);
            }

            $actorId = $this->auth->guard()->id();

            $void = new ReceiptVoid;
            $void->forceFill([
                'receipt_id' => $receipt->id,
                'reason' => $reason,
                'reversal_entry_id' => $reversal->id,
                'voided_by' => $actorId === null ? null : (string) $actorId,
            ])->save();

            $this->activityLog->record(
                'receipt.voided',
                __('Voided the receipt N.º :number: :reason', ['number' => ReceiptNumber::format($receipt->number), 'reason' => $reason]),
                $receipt,
                ['reason' => $reason, 'reversal_entry_id' => $reversal->id],
            );

            return $void->refresh();
        });
    }
}
