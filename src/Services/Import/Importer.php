<?php

namespace Sellinnate\SalesCrm\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Actions\ManageDealPeople;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\CommissionType;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\SignatureStatus;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Events\ImportFinished;
use Sellinnate\SalesCrm\Exceptions\ImportException;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Import;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\DealRequirements;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Phone;
use Throwable;

/**
 * Imports deals from a mapped sheet: the preview of the first rows (new, updated, rejected and why),
 * then every row through the actions of the CRM (deduplication of companies and people included),
 * with a report of rejected rows and warnings. The external reference ("key") updates the deals
 * already imported instead of creating new ones, so a second sheet can complete the first.
 *
 * @phpstan-type Interpreted array{reject: string|null, key: string|null, deal: array<string, mixed>, title: string, person: array<string, string>, stage: Stage|null, note: string|null, warnings: list<string>}
 */
class Importer
{
    public const MAX_STAGE_VALUES = 200;

    private const LIMITS = ['sub_status' => 160, 'approval_note' => 1000, 'commission_note' => 255];

    /** @var array<string, array<string, int|string>> the users of each workspace, by email, name and first name */
    private array $owners = [];

    /** @var array<int, Collection<int, Stage>> */
    private array $stages = [];

    public function __construct(
        private CreateDeal $create,
        private UpdateDeal $update,
        private MoveDeal $move,
        private ManageDealPeople $people,
        private ManageNotes $notes,
        private DealRequirements $requirements,
        private Matcher $matcher,
        private DealPositions $positions,
        private Access $access,
    ) {}

    public function reader(Import $import): SpreadsheetReader
    {
        return new SpreadsheetReader($import->disk, $import->stored_path, $import->sheet);
    }

    /**
     * The distinct values of a column (the stage), in order of appearance.
     *
     * @return list<string>
     */
    public function values(Import $import, int $column): array
    {
        $values = [];

        foreach ($this->reader($import)->rows() as $number => $cells) {
            $value = trim($cells[$column] ?? '');

            if ($number === 1 || $value === '' || in_array($value, $values, true)) {
                continue;
            }

            $values[] = $value;

            if (count($values) >= self::MAX_STAGE_VALUES) {
                break;
            }
        }

        return $values;
    }

    /**
     * The stage each value suggests: the stage whose name (or first word) it contains, the longest.
     *
     * @param  list<string>  $values
     * @param  Collection<int, Stage>  $stages
     * @return array<string, string>
     */
    public static function suggestStages(array $values, Collection $stages): array
    {
        $suggested = [];

        foreach ($values as $value) {
            $normalized = ' '.ImportFields::normalize($value).' ';
            $best = null;
            $length = 0;

            foreach ($stages as $stage) {
                $name = ImportFields::normalize($stage->name);
                $words = array_filter([$name, explode(' ', $name)[0]], fn (string $word): bool => mb_strlen($word) >= 3);

                foreach ($words as $word) {
                    if (str_contains($normalized, ' '.$word) && mb_strlen($word) > $length) {
                        $best = $stage;
                        $length = mb_strlen($word);
                    }
                }
            }

            $suggested[$value] = $best === null ? '' : (string) $best->id;
        }

        return $suggested;
    }

    /**
     * What each of the first rows would become: a new deal, the update of one, or nothing and why.
     *
     * @return list<array{row: int, title: string, action: string, reason: string|null, warnings: list<string>}>
     */
    public function outcomes(Import $import, ?int $rows = null): array
    {
        $rows ??= max(1, (int) config('sales-crm.import.preview_rows', 10));
        $outcomes = [];

        foreach ($this->reader($import)->rows() as $number => $cells) {
            if ($number === 1) {
                continue;
            }

            $row = $this->interpret($import, $cells);
            $exists = $row['key'] !== null && Deal::query()->where('workspace_key', $import->workspaceRef()?->key() ?? '')->where('external_ref', $row['key'])->exists();
            $reject = $row['reject'] ?? (! $exists && $row['title'] === '' ? (string) __('sales-crm::import.missing_title') : null);

            $outcomes[] = [
                'row' => $number,
                'title' => $row['title'],
                'action' => $reject !== null ? 'reject' : ($exists ? 'update' : 'create'),
                'reason' => $reject,
                'warnings' => $row['warnings'],
            ];

            if (count($outcomes) >= $rows) {
                break;
            }
        }

        return $outcomes;
    }

    /**
     * Read the whole sheet and write its deals, keeping the counts and the report on the import.
     */
    public function run(Import $import, Authenticatable $by): void
    {
        $import->forceFill(['status' => ImportStatus::Running, 'started_at' => CarbonImmutable::now(), 'error' => null])->save();

        $created = $updated = $total = 0;
        $rejected = $warnings = [];

        try {
            foreach ($this->reader($import)->rows() as $line => $cells) {
                if ($line === 1) {
                    continue;
                }

                $total++;

                try {
                    $row = $this->interpret($import, $cells);

                    if ($row['reject'] !== null) {
                        $rejected[] = ['row' => $line, 'reason' => $row['reject']];

                        continue;
                    }

                    [$action, $more] = $this->write($import, $row, $by);
                    $action === 'create' ? $created++ : $updated++;

                    foreach ([...$row['warnings'], ...$more] as $warning) {
                        $warnings[] = ['row' => $line, 'message' => Str::ucfirst($warning)];
                    }
                } catch (ValidationException $exception) {
                    $rejected[] = ['row' => $line, 'reason' => (string) collect($exception->errors())->flatten()->first()];
                } catch (ImportException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    // The class only: the message may quote the data of the row.
                    report(new RuntimeException('sales-crm: import row '.$line.' not imported ('.$exception::class.').'));
                    $rejected[] = ['row' => $line, 'reason' => (string) __('sales-crm::import.unexpected_error')];
                }
            }
        } catch (Throwable $exception) {
            report(new RuntimeException('sales-crm: import '.$import->id.' stopped ('.$exception::class.').'));

            $import->forceFill([
                'status' => ImportStatus::Failed,
                'error' => ($exception instanceof ImportException ? $exception->getMessage().' ' : '').__('sales-crm::import.stopped'),
                ...$this->counts($total, $created, $updated, $rejected, $warnings),
            ])->save();

            ImportFinished::dispatch($import);

            return;
        }

        $import->forceFill(['status' => ImportStatus::Done, ...$this->counts($total, $created, $updated, $rejected, $warnings)])->save();

        ImportFinished::dispatch($import);
    }

    /**
     * @param  list<array{row: int, reason: string}>  $rejected
     * @param  list<array{row: int, message: string}>  $warnings
     * @return array<string, mixed>
     */
    private function counts(int $total, int $created, int $updated, array $rejected, array $warnings): array
    {
        return [
            'total_rows' => $total,
            'created_count' => $created,
            'updated_count' => $updated,
            'rejected_count' => count($rejected),
            'rejected' => $rejected,
            'warnings' => $warnings,
            'finished_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * A row as the deal, its person, its stage and its note.
     *
     * @param  list<string>  $cells
     * @return Interpreted
     */
    public function interpret(Import $import, array $cells): array
    {
        $columns = $import->mapping['columns'] ?? [];
        $monthFirst = (bool) ($import->mapping['month_first'] ?? false);
        $values = [];

        foreach ($columns as $index => $field) {
            $text = trim($cells[(int) $index] ?? '');

            if ($field !== '' && $text !== '') {
                $values[$field][] = $text;
            }
        }

        $first = fn (string $field): ?string => $values[$field][0] ?? null;
        $text = fn (string $field): ?string => isset($values[$field]) ? implode("\n", $values[$field]) : null;
        $deal = [];
        $notes = [];
        $warnings = [];
        $company = $first('company');
        $title = $first('title');

        if ($title !== null) {
            $deal['title'] = self::clip($title, 200);
        }

        // The key is never cut: two keys cut alike would be the same deal.
        $reject = null;

        if (($key = $first('external_ref')) !== null && mb_strlen($key) > 64) {
            $reject = (string) __('sales-crm::import.key_too_long');
        } elseif ($key !== null) {
            $deal['external_ref'] = $key;
        }

        foreach (ImportFields::TEXTS as $field) {
            if ($field !== 'note' && ($value = $text($field)) !== null) {
                $deal[$field] = self::clip($value, self::LIMITS[$field] ?? 2000);
            }
        }

        // A value not read leaves the field as it is, and says so.
        if (($priority = $first('priority')) !== null) {
            ($read = ImportValues::priority($priority)) !== null ? $deal['priority'] = $read : $warnings[] = (string) __('sales-crm::import.warnings.priority', ['value' => $priority]);
        }

        if (($owner = $first('owner')) !== null) {
            ($id = $this->owner($import, $owner)) !== null ? $deal['owner_id'] = $id : $warnings[] = (string) __('sales-crm::import.warnings.owner', ['value' => $owner]);
        }

        if (($source = $first('source')) !== null) {
            ($read = ImportValues::source($source)) !== null ? $deal['source'] = $read->value : $warnings[] = (string) __('sales-crm::import.warnings.source', ['value' => $source]);
        }

        if (($tags = $first('tags')) !== null) {
            $deal['tags'] = array_slice(array_values(array_filter(array_map(fn (string $tag): string => Str::limit(trim($tag), 30, ''), preg_split('/[,;]/', $tags) ?: []))), 0, max(1, (int) config('sales-crm.deals.max_tags', 10)));
        }

        foreach (['amount_once', 'deposit'] as $field) {
            if (($amount = $first($field)) !== null) {
                ($cents = ImportValues::cents($amount)) !== null ? $deal[$field] = $cents / 100 : $notes[] = ImportFields::label($field).': '.$amount;
            }
        }

        if (($recurring = $text('recurring_options')) !== null && ($read = ImportValues::recurring($recurring)) !== null) {
            $deal['recurring'] = $read['cents'] / 100;
            $deal['recurring_period'] = $read['period']->value;
        }

        if (($commission = $text('commission')) !== null) {
            $deal['commission_note'] = self::clip($commission, self::LIMITS['commission_note']);

            if (($cents = ImportValues::cents($commission)) !== null) {
                $deal['commission_type'] = CommissionType::Amount->value;
                $deal['commission_value'] = $cents / 100;
            }
        }

        foreach (['expected_close_date', 'offer_valid_until', 'last_contacted_at', 'data_verified_at'] as $field) {
            if (($date = $first($field)) !== null) {
                ($read = ImportValues::date($date, $monthFirst)) !== null ? $deal[$field] = $read : $notes[] = ImportFields::label($field).': '.$date;
            }
        }

        foreach (['signature' => 'signature_status', 'payment' => 'payment_status'] as $field => $column) {
            if (($label = $first($field)) === null) {
                continue;
            }

            $read = $field === 'signature' ? ImportValues::signature($label) : ImportValues::payment($label);

            if ($read === null) {
                $warnings[] = (string) __('sales-crm::import.warnings.'.$field.'_unknown', ['value' => $label]);
                $notes[] = ImportFields::label($field).': '.$label;
            } else {
                $deal[$column] = $read['status']->value;

                if ($read['warning'] !== null) {
                    $warnings[] = $read['warning'];
                }
            }
        }

        $custom = collect(CustomFields::of($import->workspaceRef()))->keyBy('key');

        foreach ($values as $field => $texts) {
            if (str_starts_with($field, 'custom:') && $custom->has($key = substr($field, 7))) {
                $definition = $custom->get($key);
                $value = $texts[0];
                $read = match ($definition['type'] ?? 'text') {
                    'number' => ImportValues::number($value),
                    'date' => ImportValues::date($value, $monthFirst),
                    'boolean' => ImportValues::boolean($value),
                    'choice' => collect($definition['options'] ?? [])->first(fn (string $option): bool => Str::lower($option) === Str::lower(trim($value))),
                    default => self::clip($value, 500),
                };

                $read === null ? $notes[] = $definition['label'].': '.$value : $deal['custom_values'][$key] = $read;
            }
        }

        if ($company !== null) {
            $deal['company_name'] = Str::limit($company, 199, '');
            $deal['company_vat'] = $first('company_vat') === null ? null : Str::limit((string) $first('company_vat'), 32, '');
            $deal['company_website'] = $first('company_website') === null ? null : Str::limit((string) $first('company_website'), 255, '');
        }

        $person = array_filter([
            'email' => Str::lower((string) $first('person_email')),
            'mobile' => (string) $first('person_mobile'),
            'phone' => (string) $first('person_phone'),
            'phone_source' => Str::limit((string) $first('person_phone_source'), 255, ''),
            'job_title' => Str::limit((string) $first('person_job_title'), 160, ''),
        ], fn (string $value): bool => $value !== '');

        if (($name = $first('person_name')) !== null) {
            $words = preg_split('/\s+/', $name) ?: [];
            $person = ['first_name' => Str::limit((string) array_shift($words), 120, ''), 'last_name' => Str::limit(implode(' ', $words), 120, ''), ...$person];
        }

        // An email that is not an email, or a number that is not one phone, stays in the note.
        if (isset($person['email']) && filter_var($person['email'], FILTER_VALIDATE_EMAIL) === false) {
            $notes[] = ImportFields::label('person_email').': '.$person['email'];
            unset($person['email']);
        }

        foreach (['mobile' => 'person_mobile', 'phone' => 'person_phone'] as $field => $label) {
            if (isset($person[$field]) && Phone::normalize($person[$field]) === null) {
                $notes[] = ImportFields::label($label).': '.$person[$field];
                unset($person[$field]);
            }
        }

        $stageText = $first('stage');
        $stage = $this->stage($import, $stageText);

        // The text of the stage column is kept: in the sub-status when the sheet has none, else in the note.
        if ($stageText !== null) {
            if (! isset($deal['sub_status'])) {
                $deal['sub_status'] = self::clip($stageText, self::LIMITS['sub_status']);
            } elseif (mb_strtolower($stageText) !== mb_strtolower((string) $deal['sub_status'])) {
                $notes[] = ImportFields::label('stage').': '.$stageText;
            }
        }

        if (($note = $text('note')) !== null) {
            $notes[] = $note;
        }

        $person = array_filter($person, fn (string $value): bool => $value !== '');

        return [
            // Without title, company and key the row is nothing; with a key it may complete a deal.
            'reject' => $reject ?? (! isset($deal['title']) && $company === null && ! isset($deal['external_ref']) ? (string) __('sales-crm::import.missing_title') : null),
            'key' => isset($deal['external_ref']) ? (string) $deal['external_ref'] : null,
            'deal' => $deal,
            'title' => self::clip($title ?? $company ?? '', 200),
            'person' => array_intersect_key($person, array_flip(['first_name', 'last_name', 'email', 'mobile', 'phone'])) === [] ? [] : $person,
            'stage' => $stage,
            'note' => $notes === [] ? null : implode("\n", $notes),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Interpreted  $row
     * @return array{string, list<string>}
     */
    private function write(Import $import, array $row, Authenticatable $by): array
    {
        // The row whole or nothing of it, under the lock of the deduplication and of the board it writes.
        $workspace = $import->workspaceRef();
        $existing = $row['key'] === null ? null : Deal::query()->where('workspace_key', $workspace?->key() ?? '')->where('external_ref', $row['key'])->value('pipeline_id');
        $pipelineId = $existing !== null ? (int) $existing : $import->pipeline_id;
        $write = fn (): array => Crm::db()->transaction(fn (): array => $this->writeRow($import, $row, $by));

        return $this->matcher->locked($workspace, fn (): array => $pipelineId === null ? $write() : $this->positions->locked((int) $pipelineId, $write));
    }

    /**
     * @param  Interpreted  $row
     * @return array{string, list<string>}
     */
    private function writeRow(Import $import, array $row, Authenticatable $by): array
    {
        $warnings = [];
        $workspace = $import->workspaceRef();
        $existing = $row['key'] === null ? null : Deal::query()->where('workspace_key', $workspace?->key() ?? '')->where('external_ref', $row['key'])->first();

        if ($existing === null) {
            $pipeline = Pipeline::query()->inWorkspace($workspace)->find($import->pipeline_id);
            $stage = $row['stage'] ?? $this->defaultStage($import);

            if ($pipeline === null || $stage === null) {
                throw ValidationException::withMessages(['stage_id' => __('sales-crm::import.no_stage')]);
            }

            if ($row['title'] === '') {
                throw ValidationException::withMessages(['title' => __('sales-crm::import.missing_title')]);
            }

            // An owner not read stays empty, never whoever imports.
            $data = ['source' => DealSource::Import->value, 'owner_id' => null, 'title' => $row['title'], ...$row['deal'], 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id];

            if ($stage->kind === StageKind::Lost && blank($data['lost_reason'] ?? null)) {
                $data['lost_reason'] = LostReason::Other->value;
                $warnings[] = (string) __('sales-crm::import.warnings.lost_without_reason');
            }

            if ($row['person'] !== []) {
                $data['person'] = $row['person'];
            }

            $deal = $this->create->handle($workspace, $data, $by, requirements: false, append: true);
            $action = 'create';
            $missing = $this->requirements->missing($deal, $stage);

            if ($missing !== []) {
                $warnings[] = (string) __('sales-crm::import.warnings.requirements', [
                    'stage' => $stage->name,
                    'fields' => implode(', ', array_map(fn (RequiredField $field): string => mb_strtolower($field->label()), $missing)),
                ]);
            }
        } else {
            // A signature or a payment verified with its evidence is never taken back by a sheet.
            $fields = $row['deal'];

            if ($existing->signature_status === SignatureStatus::Signed) {
                unset($fields['signature_status']);
            }

            if ($existing->payment_status->needsEvidence()) {
                unset($fields['payment_status']);
            }

            $deal = $this->update->handle($existing, $fields, $by);

            if ($row['person'] !== []) {
                $this->people->attach($deal, $row['person'], $by);
            }

            if ($row['stage'] !== null && $row['stage']->id !== $deal->stage_id) {
                try {
                    $this->move->handle($deal, $row['stage'], $by, $row['stage']->kind === StageKind::Lost ? ['lost_reason' => $deal->lost_reason->value ?? LostReason::Other->value] : []);
                } catch (ValidationException $exception) {
                    $deal->loadMissing('stage');
                    $warnings[] = (string) __('sales-crm::import.warnings.stays', ['stage' => $deal->stage->name, 'reason' => (string) collect($exception->errors())->flatten()->first()]);
                }
            }

            $action = 'update';
        }

        if ($row['note'] !== null) {
            // The same sheet run again does not write the same note twice.
            $body = self::clip((string) __('sales-crm::import.note_prefix', ['file' => $import->original_filename])."\n".$row['note'], ManageNotes::MAX_LENGTH);

            if ($action === 'create' || ! $deal->notes()->where('body', $body)->exists()) {
                $this->notes->add($deal, $body, $by, notify: false);
            }
        }

        return [$action, $warnings];
    }

    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    private function stage(Import $import, ?string $value): ?Stage
    {
        $id = $value === null ? null : ($import->mapping['stages'][$value] ?? null);

        return filled($id) ? $this->stages($import)->firstWhere('id', (int) $id) : null;
    }

    private function defaultStage(Import $import): ?Stage
    {
        $id = $import->mapping['default_stage_id'] ?? null;
        $stages = $this->stages($import);

        return (filled($id) ? $stages->firstWhere('id', (int) $id) : null) ?? $stages->first(fn (Stage $stage): bool => $stage->kind === StageKind::Open);
    }

    /**
     * @return Collection<int, Stage>
     */
    private function stages(Import $import): Collection
    {
        return $this->stages[$import->id] ??= Stage::query()->where('pipeline_id', (int) $import->pipeline_id)->orderBy('position')->get();
    }

    /**
     * A user of the workspace by email, name or (when only one has it) first name.
     */
    private function owner(Import $import, string $text): int|string|null
    {
        $key = $import->workspaceRef()?->key() ?? '';

        if (! isset($this->owners[$key])) {
            $owners = [];
            $first = [];

            foreach ($this->access->members($import->workspaceRef())->get() as $user) {
                /** @var Model $user */
                $id = Crm::userKey($user);

                if ($id === null) {
                    continue;
                }

                $owners[Str::lower((string) Crm::userEmail($user))] = $id;
                $owners[Str::lower(Crm::userName($user))] = $id;
                $first[Str::lower(Crm::firstName(Crm::userName($user)))][] = $id;
            }

            foreach ($first as $name => $ids) {
                if (count($ids) === 1) {
                    $owners[$name] ??= $ids[0];
                }
            }

            unset($owners['']);
            $this->owners[$key] = $owners;
        }

        return $this->owners[$key][Str::lower(trim($text))] ?? null;
    }
}
