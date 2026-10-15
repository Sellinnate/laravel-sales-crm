<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Models\WorkspaceSetting;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The settings of the CRM of a workspace, for managers: the entry stage of the deals created by
 * integrations ({@see CreateDeal::fromOrigin()}) and the custom fields of its deals.
 *
 * @phpstan-import-type CustomField from CustomFields
 */
class SaveWorkspaceSettings
{
    /**
     * @param  array{entry_stage_id?: mixed}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, Authenticatable $by): WorkspaceSetting
    {
        $workspace = Workspace::of($workspace);
        Gate::forUser($by)->authorize('create', [Pipeline::class, $workspace]);

        $validated = Validator::make($data, ['entry_stage_id' => ['nullable', 'integer']])->validate();
        $stageId = filled($validated['entry_stage_id'] ?? null) ? (int) $validated['entry_stage_id'] : null;

        if ($stageId !== null && ! Stage::query()->whereKey($stageId)->where('kind', StageKind::Open->value)
            ->whereIn('pipeline_id', Pipeline::query()->inWorkspace($workspace)->select('id'))->exists()) {
            throw ValidationException::withMessages(['entry_stage_id' => __('sales-crm::validation.entry_stage')]);
        }

        $settings = WorkspaceSetting::for($workspace);

        if (array_key_exists('entry_stage_id', $validated)) {
            $settings->entry_stage_id = $stageId;
        }

        $settings->updated_by = Crm::userKey($by);
        $settings->save();

        return $settings;
    }

    /**
     * The custom fields of the deals of the workspace: key, label, type and the options of a choice.
     * The key names the values on the deals: a field taken away keeps its values there, unseen.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<CustomField>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function customFields(Model|Workspace|null $workspace, array $fields, Authenticatable $by): array
    {
        $workspace = Workspace::of($workspace);
        Gate::forUser($by)->authorize('create', [Pipeline::class, $workspace]);

        $fields = array_map(fn (array $field): array => [
            'key' => trim(is_scalar($field['key'] ?? null) ? (string) $field['key'] : ''),
            'label' => trim(is_scalar($field['label'] ?? null) ? (string) $field['label'] : ''),
            'type' => is_scalar($field['type'] ?? null) ? (string) $field['type'] : 'text',
            'options' => array_values(array_filter(array_map(
                fn (mixed $option): string => trim(is_scalar($option) ? (string) $option : ''),
                is_array($field['options'] ?? null) ? $field['options'] : (preg_split('/[,;\n]/', is_scalar($field['options'] ?? null) ? (string) $field['options'] : '') ?: []),
            ), fn (string $option): bool => $option !== '')),
        ], $fields);

        Validator::make(['fields' => $fields], [
            'fields' => ['array', 'max:'.CustomFields::limit()],
            'fields.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'fields.*.type' => ['required', Rule::in(CustomFields::TYPES)],
            'fields.*.options' => ['array', 'max:30'],
            'fields.*.options.*' => ['string', 'max:60'],
        ], [
            'fields.max' => __('sales-crm::validation.custom_fields_max', ['max' => CustomFields::limit()]),
            'fields.*.key.regex' => __('sales-crm::validation.custom_field_key'),
            'fields.*.key.distinct' => __('sales-crm::validation.custom_field_key_distinct'),
            'fields.*.label.distinct' => __('sales-crm::validation.custom_field_label_distinct'),
        ], [
            'fields.*.key' => mb_strtolower((string) __('sales-crm::fields.custom.key')),
            'fields.*.label' => mb_strtolower((string) __('sales-crm::fields.custom.label')),
            'fields.*.type' => mb_strtolower((string) __('sales-crm::fields.custom.type')),
            'fields.*.options' => mb_strtolower((string) __('sales-crm::fields.custom.options')),
        ])->after(function ($validator) use ($fields): void {
            foreach ($fields as $index => $field) {
                if ($field['type'] === 'choice' && $field['options'] === []) {
                    $validator->errors()->add('fields.'.$index.'.options', (string) __('sales-crm::validation.custom_field_options'));
                }
            }
        })->validate();

        /** @var list<CustomField> $definitions */
        $definitions = array_map(fn (array $field): array => $field['type'] === 'choice' ? $field : array_diff_key($field, ['options' => true]), $fields);

        $settings = WorkspaceSetting::for($workspace);
        $settings->custom_fields = $definitions === [] ? null : $definitions;
        $settings->updated_by = Crm::userKey($by);
        $settings->save();

        return $definitions;
    }
}
