<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Spreadsheets\SpreadsheetWriter;
use App\Modules\Treasury\Application\Import\ImportOfferings;
use App\Modules\Treasury\Application\Import\PreviewOfferingImport;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import of offerings from an Excel workbook (offerings.manage, enforced by the routes):
 * the template, a preview that writes nothing, and the import itself (all or nothing).
 */
class OfferingImportController extends Controller
{
    private const array FILE_RULES = ['required', 'file', 'mimes:xlsx', 'max:5120'];

    public function template(): StreamedResponse
    {
        $categories = OfferingCategory::query()->where('is_active', true)->orderBy('name')->pluck('name')->all();

        return SpreadsheetWriter::download('plantilla-ofrendas.xlsx', [
            'Ofrendas' => [
                'headings' => PreviewOfferingImport::HEADERS,
                'rows' => [],
            ],
            'Instrucciones' => [
                'headings' => ['Columna', 'Qué escribir'],
                'rows' => [
                    ['fecha', 'Día de la ofrenda, DD/MM/AAAA (o una fecha de Excel). No puede ser futura.'],
                    ['miembro', 'Nombre completo tal como está en el padrón. Vacío = ofrenda anónima.'],
                    ['categoria', 'Una de las categorías activas de abajo, escrita igual (sin importar tildes ni mayúsculas).'],
                    ['concepto', 'Opcional, hasta 200 caracteres.'],
                    ['bs', 'Monto en bolívares. Ejemplos: 20000 · 20.000,50 · 20000,50. Vacío = 0.'],
                    ['usd', 'Monto en dólares, mismo formato.'],
                    ['cop', 'Monto en pesos, mismo formato.'],
                    ['', 'Cada fila necesita al menos un monto mayor que cero. Las monedas no se convierten.'],
                    ['', 'Si una sola fila tiene un error, no se importa nada: la vista previa dice cuál corregir.'],
                    ['', ''],
                    ['Categorías activas', ''],
                    ...array_map(fn (string $name) => ['', $name], $categories),
                ],
            ],
        ]);
    }

    public function preview(Request $request, PreviewOfferingImport $preview): JsonResponse
    {
        $request->validate(['file' => self::FILE_RULES]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return response()->json($preview($file->getRealPath())->toArray());
    }

    public function store(Request $request, ImportOfferings $import): RedirectResponse
    {
        $request->validate([
            'file' => self::FILE_RULES,
            'confirm_duplicates' => ['nullable', 'boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $count = $import($file->getRealPath(), $request->boolean('confirm_duplicates'));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count offering imported.|[2,*] :count offerings imported.', $count, ['count' => $count])]);

        return back();
    }
}
