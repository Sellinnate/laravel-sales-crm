<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Models\EmailTemplate;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The email templates of a workspace: created, changed and deleted by managers, used by everybody who
 * writes from its deals.
 */
class SaveEmailTemplate
{
    /**
     * @param  array<string, mixed>  $data  name, subject, body
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, Authenticatable $by, ?EmailTemplate $template = null): EmailTemplate
    {
        $workspace = Workspace::of($workspace);
        Gate::forUser($by)->authorize('create', [Pipeline::class, $workspace]);

        if ($template !== null && ! $template->inWorkspace($workspace)) {
            throw ValidationException::withMessages(['name' => __('sales-crm::validation.other_workspace')]);
        }

        $data = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $data);

        /** @var array{name: string, subject: string, body: string} $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', Rule::unique(Crm::table('email_templates'), 'name')->where('workspace_key', $workspace?->key() ?? '')->ignore($template?->id)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ], ['name.unique' => __('sales-crm::validation.template_name')], [
            'name' => mb_strtolower((string) __('sales-crm::fields.template.name')),
            'subject' => mb_strtolower((string) __('sales-crm::fields.template.subject')),
            'body' => mb_strtolower((string) __('sales-crm::fields.template.body')),
        ])->validate();

        $template ??= (new EmailTemplate(['created_by' => Crm::userKey($by)]))->setWorkspace($workspace);
        $template->fill($validated)->save();

        return $template;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(EmailTemplate $template, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('create', [Pipeline::class, $template->workspaceRef()]);

        $template->delete();
    }
}
