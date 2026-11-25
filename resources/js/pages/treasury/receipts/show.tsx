import { Head, Link } from '@inertiajs/react';
import { Ban, BookOpen } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { ReceiptVoidDialog } from '@/features/treasury/components/receipt-void-dialog';
import { formatShortDate } from '@/features/treasury/rates';
import type { ReceiptDetail } from '@/features/treasury/types';
import { formatDateTime } from '@/lib/date';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { cn } from '@/lib/utils';
import { index as journalIndex } from '@/routes/accounting/journal';
import { index as receiptsIndex } from '@/routes/treasury/receipts';

type ReceiptShowProps = {
    receipt: ReceiptDetail;
    can_administer: boolean;
};

const CURRENCIES = [
    { key: 'amount_ves', label: 'Bolívares' },
    { key: 'amount_usd', label: 'Dólares' },
    { key: 'amount_cop', label: 'Pesos COP' },
] as const;

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-1 border-b py-3 last:border-0 sm:grid-cols-[14rem_1fr] sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="font-semibold break-words">{children}</dd>
        </div>
    );
}

export default function ReceiptShow({
    receipt,
    can_administer: canAdminister,
}: ReceiptShowProps) {
    const [isVoiding, setIsVoiding] = useState(false);
    const isExpense = receipt.type === 'expense';
    const amounts = CURRENCIES.filter(({ key }) => !isZeroAmount(receipt[key]));

    return (
        <>
            <Head title={`Comprobante ${receipt.number_label}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Comprobante"
                    title={`N.º ${receipt.number_label}`}
                    description={`${receipt.type_label} del ${formatShortDate(receipt.issued_on)}.`}
                    meta={receipt.void ? 'Anulado' : 'Emitido'}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={journalIndex({
                                        query: { month: receipt.month },
                                    })}
                                >
                                    <BookOpen aria-hidden />
                                    Ver en el Libro Diario
                                </Link>
                            </Button>
                            {canAdminister && !receipt.void && (
                                <Button
                                    variant="destructive"
                                    onClick={() => setIsVoiding(true)}
                                >
                                    <Ban aria-hidden />
                                    Anular
                                </Button>
                            )}
                        </>
                    }
                />

                {receipt.void && (
                    <p
                        role="status"
                        className="rounded-2xl border-2 border-destructive-foreground/40 bg-destructive/10 px-4 py-3 text-sm"
                    >
                        <span className="font-black tracking-[0.15em] text-destructive-foreground uppercase">
                            Anulado
                        </span>{' '}
                        · {receipt.void.reason}
                        <span className="block text-muted-foreground">
                            {formatDateTime(receipt.void.voided_at)}
                            {receipt.void.voided_by &&
                                ` · ${receipt.void.voided_by}`}
                            . Conserva su número y lo que dice; su asiento se
                            deshizo con un contraasiento.
                        </span>
                    </p>
                )}

                <section
                    aria-label="Lo que dice el comprobante"
                    className={cn(
                        'rounded-[2rem] border-2 border-border bg-card px-6 py-3 md:px-8',
                        receipt.void && 'opacity-80',
                    )}
                >
                    <dl>
                        <Row label="Fecha de emisión">
                            {formatShortDate(receipt.issued_on)}
                        </Row>
                        <Row label="Tipo">{receipt.type_label}</Row>
                        <Row label="Código contable">
                            <span className="font-mono">
                                {receipt.account_code}
                            </span>{' '}
                            {receipt.account_name}
                        </Row>
                        {receipt.holder && (
                            <Row label="Titular">{receipt.holder}</Row>
                        )}
                        {receipt.counterparty && (
                            <Row
                                label={
                                    isExpense
                                        ? 'Hemos pagado a'
                                        : 'Hemos recibido de'
                                }
                            >
                                {receipt.counterparty}
                            </Row>
                        )}
                        <Row label="Monto">
                            <span className="flex flex-col gap-1 tabular-nums">
                                {amounts.map(({ key, label }) => (
                                    <span
                                        key={key}
                                        className="text-xl font-black"
                                    >
                                        <span
                                            className={cn(
                                                receipt.void &&
                                                    'line-through decoration-2',
                                            )}
                                        >
                                            {formatAmount(receipt[key])}
                                        </span>{' '}
                                        <span className="text-sm font-medium text-muted-foreground">
                                            {label}
                                        </span>
                                    </span>
                                ))}
                            </span>
                        </Row>
                        {receipt.concept && (
                            <Row label="Por concepto de">{receipt.concept}</Row>
                        )}
                        <Row label="Forma de pago">
                            {receipt.payment_method_label}
                        </Row>
                        {receipt.bank && (
                            <Row label="Banco">{receipt.bank}</Row>
                        )}
                        {receipt.payment_reference && (
                            <Row label="N.º de referencia">
                                {receipt.payment_reference}
                            </Row>
                        )}
                        {receipt.payment_date && (
                            <Row label="Fecha del pago">
                                {formatShortDate(receipt.payment_date)}
                            </Row>
                        )}
                        {(receipt.received_by_name ||
                            receipt.received_by_id_number) && (
                            <Row label="Recibí conforme">
                                {receipt.received_by_name}
                                {receipt.received_by_id_number &&
                                    ` · C.I. ${receipt.received_by_id_number}`}
                            </Row>
                        )}
                        {(receipt.delivered_by_name ||
                            receipt.delivered_by_id_number) && (
                            <Row label="Entregué conforme">
                                {receipt.delivered_by_name}
                                {receipt.delivered_by_id_number &&
                                    ` · C.I. ${receipt.delivered_by_id_number}`}
                            </Row>
                        )}
                    </dl>
                </section>

                <p className="text-sm text-muted-foreground">
                    Emitido el {formatDateTime(receipt.created_at)}
                    {receipt.issued_by && ` por ${receipt.issued_by}`}. Su
                    asiento está en el Libro Diario de{' '}
                    {formatMonth(receipt.month)}.{' '}
                    <Link
                        href={receiptsIndex()}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        Volver al talonario
                    </Link>
                </p>
            </div>
            {canAdminister && !receipt.void && (
                <ReceiptVoidDialog
                    number={receipt.number}
                    numberLabel={receipt.number_label}
                    open={isVoiding}
                    onOpenChange={setIsVoiding}
                />
            )}
        </>
    );
}

ReceiptShow.layout = {
    breadcrumbs: [{ title: 'Comprobantes', href: receiptsIndex() }],
};
