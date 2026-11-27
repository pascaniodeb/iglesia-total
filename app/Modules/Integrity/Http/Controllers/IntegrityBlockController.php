<?php

namespace App\Modules\Integrity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrity\Application\Actions\InspectBlock;
use App\Modules\Integrity\Application\Actions\ProveRecord;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One block of the sealed ledger and the proof that a record is inside it (sealed_ledger.view,
 * enforced by the route). Read-only.
 */
class IntegrityBlockController extends Controller
{
    public function show(int $height, InspectBlock $inspect): Response
    {
        return Inertia::render('integrity/block', $inspect($height) ?? abort(404));
    }

    /**
     * The proof, on screen or as a JSON file (?format=json) that can be checked without this system.
     */
    public function proof(Request $request, ProveRecord $prove, SealableSources $sources): Response|JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys($sources->all()))],
            'period' => ['required', 'string', 'max:20'],
            'record' => ['required', 'string', 'max:100'],
            'format' => ['nullable', 'string', 'in:json'],
        ]);

        $proof = $prove($data['kind'], $data['period'], $data['record']);

        if (($data['format'] ?? null) === 'json') {
            return response()->json($proof, 200, [
                'Content-Disposition' => 'attachment; filename="prueba-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $data['period'].'-'.$data['record']).'.json"',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return Inertia::render('integrity/proof', ['proof' => $proof]);
    }
}
