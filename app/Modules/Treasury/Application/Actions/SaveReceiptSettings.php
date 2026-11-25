<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Treasury\Infrastructure\Models\ReceiptSetting;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the number the receipt book starts at. Raising it begins a new physical book; the series
 * never goes back, because the next receipt is always the one after the highest issued.
 */
final readonly class SaveReceiptSettings
{
    public const int MAX = 99999999;

    public function __construct(private ActivityLog $activityLog, private AuthFactory $auth) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(int $startNumber): ReceiptSetting
    {
        if ($startNumber < 1 || $startNumber > self::MAX) {
            throw ValidationException::withMessages(['start_number' => __('Write a whole number between 1 and :max.', ['max' => self::MAX])]);
        }

        return DB::transaction(function () use ($startNumber): ReceiptSetting {
            $setting = ReceiptSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $before = ['start_number' => $setting->start_number];

            if ($setting->start_number === $startNumber) {
                return $setting;
            }

            $actorId = $this->auth->guard()->id();
            $setting->forceFill([
                'start_number' => $startNumber,
                'updated_by' => $actorId === null ? null : (string) $actorId,
                'updated_at' => now(),
            ])->save();

            $this->activityLog->record(
                'receipt_settings.updated',
                __('Changed the starting number of the receipt book from :from to :to.', ['from' => $before['start_number'], 'to' => $startNumber]),
                $setting,
                ChangeSet::between($before, ['start_number' => $startNumber]),
            );

            return $setting;
        });
    }
}
