<?php

namespace Sellinnate\SalesCrm\Exceptions;

use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * The refusal of a stage whose required fields the deal lacks: one error per field under
 * `requirements.<field>` (and `lost_reason` for a lost stage), so the board opens the mini form on
 * them and the API and MCP tools list them.
 */
final class MissingRequirements
{
    /**
     * @param  list<RequiredField>  $fields
     */
    public static function exception(Stage $stage, array $fields, bool $lostReason = false): ValidationException
    {
        $messages = [];

        foreach ($fields as $field) {
            $messages['requirements.'.$field->value] = (string) __('sales-crm::validation.requirement', ['stage' => $stage->name, 'field' => mb_strtolower($field->label())]);
        }

        if ($lostReason) {
            $messages['lost_reason'] = (string) __('sales-crm::validation.lost_reason_required');
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * The refusal of a part brought with the deal (the person, the activity, the quote) under the key
     * of that part, so the form shows it next to the field that caused it.
     */
    public static function prefixed(ValidationException $exception, string $prefix): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages[$prefix.'.'.$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * The required fields named by the errors of a refusal.
     *
     * @return list<RequiredField>
     */
    public static function fieldsOf(ValidationException $exception): array
    {
        $fields = [];

        foreach (array_keys($exception->errors()) as $key) {
            if (str_starts_with($key, 'requirements.') && ($field = RequiredField::tryFrom(substr($key, 13))) !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
