<?php

namespace Sellinnate\SalesCrm\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Sellinnate\SalesCrm\Models\Import;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\Import\Importer;
use Sellinnate\SalesCrm\Services\SpreadsheetExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The rows an import rejected, as CSV: the row, the reason and the cells as they were, to fix and
 * import again.
 */
class ImportRejectedController extends Controller
{
    public function __invoke(Import $import, Importer $importer): StreamedResponse
    {
        Gate::authorize('create', [Pipeline::class, $import->workspaceRef()]);

        $reasons = collect($import->rejected ?? [])->keyBy('row')->map(fn (array $rejected): string => $rejected['reason'])->all();
        $reader = $importer->reader($import);
        $headers = $reader->preview(1)['headers'];

        return SpreadsheetExport::download(
            'rejected-import-'.$import->id,
            'csv',
            [(string) __('sales-crm::import.row'), (string) __('sales-crm::import.reason'), ...$headers],
            (function () use ($reader, $reasons): iterable {
                foreach ($reader->rows() as $number => $cells) {
                    if (isset($reasons[$number])) {
                        yield [$number, $reasons[$number], ...$cells];
                    }
                }
            })(),
        );
    }
}
