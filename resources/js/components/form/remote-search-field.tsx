import { Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Field } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const DEBOUNCE_MS = 250;

/** One result of the search: what is shown, and an optional second line (a document). */
export type RemoteSearchOption = {
    id: string;
    name: string;
    document?: string | null;
};

type RemoteSearchFieldProps = {
    label: string;
    description?: string;
    /** The chosen option, or null. */
    value: { id: string; name: string } | null;
    onChange: (option: { id: string; name: string } | null) => void;
    /** URL that answers `{ data: RemoteSearchOption[] }` for the typed text. */
    searchUrl: (search: string) => string;
    placeholder?: string;
    /** Label of the button that clears the choice. */
    clearLabel?: string;
    /** Shown when nothing matches. */
    emptyText?: string;
    /** Accessible name of the results list. */
    resultsLabel?: string;
    error?: string;
    disabled?: boolean;
};

/**
 * Picks one record searched on the server (a registry can have thousands of rows). Results are
 * drawn in the flow, under the input: a floating panel would be clipped by the scrolling area
 * of a form or a dialog. Keyboard: arrows move, Enter chooses, Escape closes.
 */
export function RemoteSearchField({
    label,
    description,
    value,
    onChange,
    searchUrl,
    placeholder = 'Buscar…',
    clearLabel = 'Quitar',
    emptyText = 'Nada coincide.',
    resultsLabel = 'Resultados',
    error,
    disabled,
}: RemoteSearchFieldProps) {
    const listId = useId();
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<RemoteSearchOption[]>([]);
    const [active, setActive] = useState(0);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => {
            setLoading(true);
            setFailed(false);

            fetch(searchUrl(search), {
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json() as Promise<{
                        data: RemoteSearchOption[];
                    }>;
                })
                .then((body) => {
                    setResults(body.data);
                    setActive(0);
                    setLoading(false);
                })
                .catch((reason: unknown) => {
                    if ((reason as Error).name !== 'AbortError') {
                        setFailed(true);
                        setLoading(false);
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer.current);
            controller.abort();
        };
    }, [open, search, searchUrl]);

    const choose = (option: RemoteSearchOption) => {
        onChange({ id: option.id, name: option.name });
        setOpen(false);
        setSearch('');
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape') {
            setOpen(false);

            return;
        }

        if (!open || results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => (index + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => (index - 1 + results.length) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const option = results[active];

            if (option) {
                choose(option);
            }
        }
    };

    return (
        <Field label={label} error={error} description={description}>
            {(control) =>
                value ? (
                    <div className="flex min-w-0 items-center justify-between gap-3 rounded-(--control-radius) border-2 border-input bg-card px-(--control-px) py-2">
                        <span className="min-w-0 font-semibold break-words">
                            {value.name}
                        </span>
                        {!disabled && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="shrink-0"
                                onClick={() => onChange(null)}
                            >
                                <X aria-hidden />
                                {clearLabel}
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="flex min-w-0 flex-col gap-2">
                        <div className="relative">
                            <Search
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                {...control}
                                role="combobox"
                                aria-expanded={open}
                                aria-controls={listId}
                                aria-autocomplete="list"
                                aria-activedescendant={
                                    open && results[active]
                                        ? `${listId}-${active}`
                                        : undefined
                                }
                                autoComplete="off"
                                disabled={disabled}
                                placeholder={placeholder}
                                value={search}
                                className="pl-12"
                                onFocus={() => setOpen(true)}
                                onBlur={() => setOpen(false)}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setOpen(true);
                                }}
                                onKeyDown={onKeyDown}
                            />
                        </div>
                        {open && (
                            <ul
                                id={listId}
                                role="listbox"
                                aria-label={resultsLabel}
                                className="max-h-48 overflow-y-auto rounded-(--control-radius) border-2 border-border bg-card"
                            >
                                {failed ? (
                                    <li className="px-4 py-3 text-sm text-destructive-foreground">
                                        No se pudo buscar. Inténtelo de nuevo.
                                    </li>
                                ) : results.length === 0 ? (
                                    <li className="px-4 py-3 text-sm text-muted-foreground">
                                        {loading ? 'Buscando…' : emptyText}
                                    </li>
                                ) : (
                                    results.map((option, index) => (
                                        <li
                                            key={option.id}
                                            id={`${listId}-${index}`}
                                            role="option"
                                            aria-selected={index === active}
                                            // onMouseDown: the input must not lose focus before the click lands.
                                            onMouseDown={(event) => {
                                                event.preventDefault();
                                                choose(option);
                                            }}
                                            className={cn(
                                                'flex cursor-pointer flex-col px-4 py-2.5',
                                                index === active &&
                                                    'bg-accent text-accent-foreground',
                                            )}
                                        >
                                            <span className="font-medium break-words">
                                                {option.name}
                                            </span>
                                            {option.document && (
                                                <span className="text-sm text-muted-foreground">
                                                    {option.document}
                                                </span>
                                            )}
                                        </li>
                                    ))
                                )}
                            </ul>
                        )}
                    </div>
                )
            }
        </Field>
    );
}
