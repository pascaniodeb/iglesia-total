<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Journal\CashMovement;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Shared\Money\Money;
use App\Modules\Treasury\Application\Receipts\ReceiptBook;
use App\Modules\Treasury\Domain\CurrencyAmounts;
use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Issues a receipt of the IPUV book and posts it to the journal, in one transaction.
 *
 * Under the book's lock it takes the next number, posts the entry through the Accounting
 * contract (open month, no overdraft in any currency, one pair of lines per currency) and writes
 * the receipt; PostgreSQL checks, to the cent, that the entry posts exactly its amounts to CAJA
 * and to its account and that the number is the next one. Anything refused rolls everything back:
 * a receipt without its entry is worse than a visible error.
 *
 * The form's token is the receipt's id and the entry's origin, so sending the same form twice
 * issues one receipt. A cash receipt carries no bank data (it would contradict itself).
 */
final readonly class IssueReceipt
{
    private const array LOGGED = [
        'number', 'type', 'issued_on', 'account_id', 'holder', 'counterparty', 'amount_ves', 'amount_usd', 'amount_cop', 'concept',
        'payment_method', 'bank', 'payment_reference', 'payment_date', 'received_by_name', 'received_by_id_number',
        'delivered_by_name', 'delivered_by_id_number',
    ];

    /** The data of a person: the log says it changed, never what it was. */
    private const array SENSITIVE = ['received_by_id_number', 'delivered_by_id_number'];

    /** What the accounting contract calls the fields, by what the receipt form calls them. */
    private const array FIELDS = [
        'entry_date' => 'issued_on',
        'account_code' => 'account_id',
        'amount_ves' => 'amount_ves',
        'amount_usd' => 'amount_usd',
        'amount_cop' => 'amount_cop',
    ];

    public function __construct(
        private CashMovements $movements,
        private ChartOfAccounts $chart,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $data  type, issued_on, account_id, amount_ves|usd|cop, payment_method, token and the optional text fields
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): Receipt
    {
        $type = ReceiptType::from((string) $data['type']);
        $method = PaymentMethod::from((string) $data['payment_method']);
        $token = (string) $data['token'];

        $amounts = $this->amounts($data);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['issued_on']);

        if ($date === false || $date->format('Y-m-d') !== $data['issued_on']) {
            throw ValidationException::withMessages(['issued_on' => __('Choose a valid date.')]);
        }

        return DB::transaction(function () use ($data, $type, $method, $token, $amounts): Receipt {
            // One issuer at a time: the number is known before posting, so the entry can name it.
            DB::select("SELECT pg_advisory_xact_lock(hashtext('receipts_number'))");

            $existing = Receipt::query()->find($token);

            if ($existing !== null) {
                return $existing;
            }

            $account = $this->chart->find((string) $data['account_id']);

            if ($account === null || ! $account->isActive || $account->type->value !== $type->value) {
                throw ValidationException::withMessages(['account_id' => $type === ReceiptType::Income
                    ? __('Choose an active income account.')
                    : __('Choose an active expense account.')]);
            }

            $number = ReceiptBook::nextNumber();
            $concept = self::text($data, 'concept');

            try {
                $entry = $this->movements->record(new CashMovement(
                    kind: $type->movementKind(),
                    accountCode: $account->code,
                    amounts: $this->movementAmounts($amounts),
                    entryDate: (string) $data['issued_on'],
                    description: Str::limit(__('Receipt N.º :number', ['number' => ReceiptNumber::format($number)]).($concept === null ? '' : " · {$concept}"), 252),
                    originType: 'receipt',
                    originId: $token,
                    originPurpose: $type->value,
                ));
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(self::remap($exception->errors()));
            }

            $banked = $method->isBanked();
            $actorId = $this->auth->guard()->id();

            $receipt = new Receipt;
            $receipt->forceFill([
                'id' => $token,
                'type' => $type,
                'issued_on' => $data['issued_on'],
                'account_id' => $account->id,
                'holder' => self::text($data, 'holder'),
                'counterparty' => self::text($data, 'counterparty'),
                ...$amounts->toColumns(),
                'concept' => $concept,
                'payment_method' => $method,
                'bank' => $banked ? self::text($data, 'bank') : null,
                'payment_reference' => $banked ? self::text($data, 'payment_reference') : null,
                'payment_date' => $banked ? self::text($data, 'payment_date') : null,
                'received_by_name' => self::text($data, 'received_by_name'),
                'received_by_id_number' => self::text($data, 'received_by_id_number'),
                'delivered_by_name' => self::text($data, 'delivered_by_name'),
                'delivered_by_id_number' => self::text($data, 'delivered_by_id_number'),
                'journal_entry_id' => $entry->id,
                'created_by' => $actorId === null ? null : (string) $actorId,
            ])->save();

            $receipt->refresh();

            if ($receipt->number !== $number) {
                // The database numbers the receipt under the same lock; both must agree.
                throw new InvalidArgumentException("The receipt got the number {$receipt->number}, not {$number}.");
            }

            $this->activityLog->record(
                'receipt.issued',
                __('Issued the receipt N.º :number.', ['number' => ReceiptNumber::format($receipt->number)]),
                $receipt,
                ChangeSet::between([], Arr::only($receipt->getAttributes(), self::LOGGED), self::SENSITIVE),
            );

            return $receipt;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function amounts(array $data): CurrencyAmounts
    {
        try {
            return CurrencyAmounts::fromInput(
                self::text($data, 'amount_ves'),
                self::text($data, 'amount_usd'),
                self::text($data, 'amount_cop'),
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount_ves' => __('Write at least one amount greater than zero, with up to two decimals and no thousands separators.')]);
        }
    }

    /**
     * Only the currencies the receipt moves.
     *
     * @return array<string, Money>
     */
    private function movementAmounts(CurrencyAmounts $amounts): array
    {
        return array_filter(
            ['VES' => $amounts->ves, 'USD' => $amounts->usd, 'COP' => $amounts->cop],
            fn (Money $money) => $money->isPositive(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private static function remap(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $field => $messages) {
            $mapped[self::FIELDS[$field] ?? $field] = $messages;
        }

        return $mapped;
    }
}
