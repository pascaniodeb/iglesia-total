<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Spreadsheets\SpreadsheetWriter;
use App\Modules\Treasury\Application\Import\ImportTithes;
use App\Modules\Treasury\Application\Import\PreviewTitheImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import of tithes from an Excel workbook (tithes.manage, enforced by the routes): the
 * template, a preview that writes nothing, and the import itself (all or nothing).
 */
class TitheImportController extends Controller
{
    private const array FILE_RULES = ['required', 'file', 'mimes:xlsx', 'max:5120'];

    public function template(): StreamedResponse
    {
        return SpreadsheetWriter::download('plantilla-diezmos.xlsx', [
            'Diezmos' => [
                'headings' => PreviewTitheImport::HEADERS,
                'rows' => [],
            ],
            'Instrucciones' => [
                'headings' => ['Columna', 'Qué escribir'],
                'rows' => [
                    ['fecha', 'Día del diezmo, DD/MM/AAAA (o una fecha de Excel). No puede ser futura.'],
                    ['miembro', 'Nombre completo tal como está en el padrón (sin importar tildes ni mayúsculas). Obligatorio: un diezmo siempre es de un miembro.'],
                    ['divisa', 'VES, USD o COP. También se entiende Bs, $, Bolívares, Dólares o Pesos. Una sola moneda por fila.'],
                    ['monto', 'Mayor que cero. Ejemplos: 20000 · 20.000,50 · 20000,50.'],
                    ['', 'Si una persona diezmó en dos monedas el mismo día, use dos filas.'],
                    ['', 'Si una sola fila tiene un error, no se importa nada: la vista previa dice cuál corregir.'],
                    ['', 'Ejemplos de filas:'],
                    ['01/11/2026', 'MARIA GONZALEZ · VES · 20.000,00'],
                    ['01/11/2026', 'JOSE PEREZ · USD · 15,00'],
                    ['01/11/2026', 'ANA RAMOS · COP · 50.000,00'],
                ],
            ],
        ]);
    }

    public function preview(Request $request, PreviewTitheImport $preview): JsonResponse
    {
        $request->validate(['file' => self::FILE_RULES]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return response()->json($preview($file->getRealPath())->toArray());
    }

    public function store(Request $request, ImportTithes $import): RedirectResponse
    {
        $request->validate([
            'file' => self::FILE_RULES,
            'confirm_duplicates' => ['nullable', 'boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $count = $import($file->getRealPath(), $request->boolean('confirm_duplicates'));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count tithe imported.|[2,*] :count tithes imported.', $count, ['count' => $count])]);

        return back();
    }
}
