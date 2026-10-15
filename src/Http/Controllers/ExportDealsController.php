<?php

namespace Sellinnate\SalesCrm\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\Priority;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Services\DealColumns;
use Sellinnate\SalesCrm\Services\SpreadsheetExport;
use Sellinnate\SalesCrm\Workspaces\Workspace;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The list of deals with the filters of the page, as CSV or XLSX: one row per deal, every column
 * (the custom fields of the workspace included), formula cells neutralised.
 */
class ExportDealsController extends Controller
{
    public function __invoke(Request $request, Access $access, DealBoard $board): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable && $access->canAccess($user), 403);

        $validated = $request->validate([
            'workspace' => ['nullable', 'string', 'max:255'],
            'pipeline' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'quick' => ['nullable', 'array'],
            'quick.*' => ['string', Rule::in(DealBoard::QUICK_FILTERS)],
            'owner' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'source' => ['nullable', Rule::enum(DealSource::class)],
            'tag' => ['nullable', 'string', 'max:30'],
            'closed' => ['nullable', Rule::in(['recent', 'all'])],
            'format' => ['nullable', Rule::in(SpreadsheetExport::formats())],
        ]);

        $workspace = filled($validated['workspace'] ?? null) ? Workspace::fromKey((string) $validated['workspace']) : false;

        if ($access->resolver()->enabled()) {
            abort_if($workspace instanceof Workspace && ! $access->works($user, $workspace), 403);
        } else {
            $workspace = null;
        }

        // A pipeline asked for is one the user sees, or nothing is exported: never every pipeline instead.
        $pipeline = isset($validated['pipeline']) ? Pipeline::query()->visibleTo($user)->findOrFail((int) $validated['pipeline']) : null;
        $filters = array_filter([
            'search' => $validated['q'] ?? null,
            'quick' => $validated['quick'] ?? [],
            'owner' => $validated['owner'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'source' => $validated['source'] ?? null,
            'tag' => $validated['tag'] ?? null,
            'closed' => $validated['closed'] ?? 'recent',
        ], fn (mixed $value): bool => filled($value));

        // The custom fields of the pipeline's workspace when the board was exported without choosing one.
        $scope = $pipeline?->workspaceRef() ?? ($workspace === false ? null : $workspace);
        $columns = DealColumns::all($scope);
        $custom = collect(CustomFields::of($scope))->keyBy('key')->all();
        $query = $board->query($user, $filters, $pipeline, $workspace)->with([...DealBoard::cardRelations(), 'stage']);

        return SpreadsheetExport::download(
            'deals-'.now()->format('Y-m-d'),
            (string) ($validated['format'] ?? 'csv'),
            [(string) __('sales-crm::fields.deal.title'), ...array_values($columns)],
            (function () use ($query, $columns, $custom): iterable {
                foreach ($query->lazyById(500) as $deal) {
                    yield [$deal->title, ...array_map(fn (string $column): string => DealColumns::text($deal, $column, $custom), array_keys($columns))];
                }
            })(),
        );
    }
}
