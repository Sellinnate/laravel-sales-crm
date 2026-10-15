<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\ManageDealPeople;
use Sellinnate\SalesCrm\Actions\ManageDocuments;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * What a move or a new deal brings besides the fields of the deal: the person, the next activity and
 * the quote a stage may require, written once the deal is saved; and the activity a stage creates on
 * entering (its automation).
 *
 * @phpstan-type Completions array{person: array<string, mixed>, next_activity: array<string, mixed>, offer_document: UploadedFile|null}
 */
class DealCompletions
{
    public function __construct(
        private ManageDealPeople $people,
        private ManageActivities $activities,
        private ManageDocuments $documents,
    ) {}

    /**
     * The completions taken out of the data of a move or of a new deal.
     *
     * @param  array<string, mixed>  $data
     * @return Completions
     */
    public static function extract(array &$data): array
    {
        $person = is_array($data['person'] ?? null) ? array_filter($data['person'], fn (mixed $value): bool => $value !== null && $value !== '') : [];
        $activity = is_array($data['next_activity'] ?? null) && filled($data['next_activity']['title'] ?? null) ? $data['next_activity'] : [];
        $document = ($data['offer_document'] ?? null) instanceof UploadedFile ? $data['offer_document'] : null;

        unset($data['person'], $data['next_activity'], $data['offer_document']);

        return ['person' => $person, 'next_activity' => $activity, 'offer_document' => $document];
    }

    /**
     * The required fields the completions will satisfy once written.
     *
     * @param  Completions  $completions
     * @return list<RequiredField>
     */
    public static function satisfies(array $completions): array
    {
        return array_values(array_filter([
            $completions['person'] !== [] ? RequiredField::Person : null,
            $completions['next_activity'] !== [] ? RequiredField::NextActivity : null,
            $completions['offer_document'] !== null ? RequiredField::OfferDocument : null,
        ]));
    }

    /**
     * Write the completions on the saved deal.
     *
     * @param  Completions  $completions
     */
    public function write(Deal $deal, array $completions, ?Authenticatable $by, ?string $validUntil = null): void
    {
        try {
            if ($completions['person'] !== []) {
                $this->people->attach($deal, [...$completions['person'], 'is_primary' => $completions['person']['is_primary'] ?? ! $deal->people()->exists()], $by);
            }
        } catch (ValidationException $exception) {
            throw MissingRequirements::prefixed($exception, 'person');
        }

        try {
            if ($completions['next_activity'] !== []) {
                $this->activities->save($deal, $completions['next_activity'], $by);
            }
        } catch (ValidationException $exception) {
            throw MissingRequirements::prefixed($exception, 'next_activity');
        }

        try {
            if ($completions['offer_document'] !== null && $by !== null) {
                $this->documents->upload($deal, $completions['offer_document'], ['kind' => DocumentKind::Quote->value, 'valid_until' => $validUntil], $by);
            }
        } catch (ValidationException $exception) {
            // The errors of the file go to the field of the quote, those of its validity to the deal's.
            $messages = [];

            foreach ($exception->errors() as $key => $errors) {
                $messages[['file' => 'offer_document', 'valid_until' => 'offer_valid_until'][$key] ?? $key] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * The automation of a stage: entering it creates the activity it says, assigned to the owner of
     * the deal while they still work in the workspace, due in its days.
     */
    public function onEnter(Deal $deal, Stage $stage): void
    {
        $automation = $stage->on_enter_activity;
        $type = is_array($automation) ? ActivityType::tryFrom((string) ($automation['type'] ?? '')) : null;

        if (! is_array($automation) || blank($automation['title'] ?? null) || $type === null) {
            return;
        }

        $this->activities->save($deal, [
            'type' => $type->value,
            'title' => (string) $automation['title'],
            'due_date' => CarbonImmutable::today()->addDays(max(0, (int) ($automation['due_in_days'] ?? 0)))->toDateString(),
        ], null);
    }
}
