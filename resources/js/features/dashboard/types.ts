export type CurrencyKey = 'ves' | 'usd' | 'cop';

export type Amounts = Record<CurrencyKey, string>;

export type Totals = Amounts & { count: number };

export type TrendWeek = Totals & {
    key: string;
    start: string;
    end: string;
    closed: boolean;
};

export type CategoryTotals = Totals & { id: string; name: string };

export type OfferingsWidget = {
    month: string;
    previous_month: string;
    current: Totals;
    previous: Totals;
    weeks: TrendWeek[];
    categories: CategoryTotals[];
};

export type TithesWidget = Totals & { month: string; members: number };

export type PendingWidget = {
    weeks: { key: string; end: string }[];
    weeks_pending: number;
    week_can_close: boolean;
    month: { key: string; blocker: string | null } | null;
    accounting: { key: string; blocker: string | null } | null;
};

export type CashStatement = {
    opening: string;
    income: string;
    expense: string;
    capital: string;
    exchange: string;
    closing: string;
};

export type CashWidget = Record<'VES' | 'USD' | 'COP', CashStatement>;

export type RatesWidget = {
    date: string;
    usd: string;
    cop: string;
    eur: string | null;
};

export type DashboardProps = {
    today: string;
    month: string;
    offerings: OfferingsWidget | null;
    tithes: TithesWidget | null;
    pending: PendingWidget | null;
    cash: CashWidget | null;
    rates: RatesWidget | null;
};
