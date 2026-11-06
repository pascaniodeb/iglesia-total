<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Treasury\Application\Actions\RecordExchangeRates;
use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\Rate;
use App\Modules\Treasury\Domain\Rates\RatesUnavailable;
use App\Modules\Treasury\Infrastructure\Models\ExchangeRate;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The exchange rates screen (catalogs.view to read; catalogs.manage to sync or correct).
 * Ported from safipuv-v4.
 */
class ExchangeRateController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['date' => 'exchange_rates.rate_date'], 'date', defaultDirection: 'desc', withStatus: false);
        $latest = ExchangeRate::query()->orderByDesc('rate_date')->first();

        return Inertia::render('treasury/exchange-rates/index', [
            // Searched by the date as it is read (dd/mm/yyyy): "10/2026" brings October.
            'rates' => $table->paginate(ExchangeRate::query(), ["to_char(exchange_rates.rate_date, 'DD/MM/YYYY')"])
                ->through(fn (ExchangeRate $rate) => self::row($rate)),
            'filters' => $table->filters(),
            'latest' => $latest === null ? null : self::row($latest),
            'today' => RecordExchangeRates::today()->format('Y-m-d'),
            'can_manage' => $request->user()?->can(Permission::CatalogsManage->value) ?? false,
        ]);
    }

    public function store(Request $request, RecordExchangeRates $rates): RedirectResponse
    {
        [$date, $usd, $cop] = $this->manualRates($request);
        $rate = $rates->record($date, $usd, $cop);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The rates of :date were saved. The sync no longer changes them.', ['date' => $rate->rate_date->format('d/m/Y')]),
        ]);

        return back();
    }

    public function sync(RecordExchangeRates $rates): RedirectResponse
    {
        try {
            $result = $rates->sync();
        } catch (RatesUnavailable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The BCV or the TRM did not answer. Try again in a few minutes or record the rate by hand.')]);

            return back();
        }

        Inertia::flash('toast', match ($result) {
            RecordExchangeRates::SYNCED => ['type' => 'success', 'message' => __('Today\'s rates were updated from the BCV and the TRM.')],
            RecordExchangeRates::UNCHANGED => ['type' => 'success', 'message' => __('Today\'s rates were already up to date.')],
            RecordExchangeRates::KEPT_MANUAL => ['type' => 'warning', 'message' => __('Today has a rate recorded by hand: it is kept.')],
        });

        return back();
    }

    /**
     * @return array{id: string, date: string, usd_rate: string, eur_rate: string|null, trm_cop_usd: string|null, cop_rate: string, origin: string, origin_label: string}
     */
    private static function row(ExchangeRate $rate): array
    {
        return [
            'id' => $rate->id,
            'date' => $rate->rate_date->toDateString(),
            'usd_rate' => $rate->usd_rate,
            'eur_rate' => $rate->eur_rate,
            'trm_cop_usd' => $rate->trm_cop_usd,
            'cop_rate' => $rate->cop_rate,
            'origin' => $rate->origin->value,
            'origin_label' => $rate->origin->label(),
        ];
    }

    /**
     * Date and rates of the manual form (a decimal comma is accepted).
     *
     * @return array{0: DateTimeImmutable, 1: Rate, 2: Rate}
     *
     * @throws ValidationException
     */
    private function manualRates(Request $request): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $request->string('rate_date')->toString());
        $usd = self::rateFrom($request, 'usd_rate', DailyRates::USD_SCALE);
        $cop = self::rateFrom($request, 'cop_rate', DailyRates::COP_SCALE);

        if ($date === false || $usd === null || $cop === null) {
            throw ValidationException::withMessages(array_filter([
                'rate_date' => $date === false ? __('Choose the date.') : null,
                'usd_rate' => $usd === null ? __('Write the bolívares per dollar, greater than zero and with up to 4 decimals.') : null,
                'cop_rate' => $cop === null ? __('Write the pesos per bolívar, greater than zero and with up to 6 decimals.') : null,
            ]));
        }

        return [$date, $usd, $cop];
    }

    /**
     * @param  int<0, max>  $scale
     */
    private static function rateFrom(Request $request, string $field, int $scale): ?Rate
    {
        try {
            return Rate::of(str_replace(',', '.', trim($request->string($field)->toString())), $scale);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
