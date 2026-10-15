<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Workspaces\Workspace;

class CrmDealCreateTool extends CrmTool
{
    protected string $name = 'crm_deal_create';

    protected string $description = 'Creates a deal. Amounts are in major currency units (1500 = 1,500.00). Without pipeline_id the default pipeline of the workspace is used, without stage_id its first open stage. company_name (with the optional company_vat and company_website) is matched against the existing companies by VAT number, domain and name before creating one; person {first_name, last_name, email, mobile} becomes the primary contact. If the stage requires fields the error lists them: pass them and retry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->required(),
            'workspace' => $schema->string()->description('Workspace key ("type|id"), required when the CRM is split by workspace and no pipeline_id is given.'),
            'pipeline_id' => $schema->integer(),
            'stage_id' => $schema->integer(),
            'company_name' => $schema->string()->max(200),
            'company_vat' => $schema->string()->max(32)->description('VAT number of the company, to match it or give it to a new one.'),
            'company_website' => $schema->string()->max(255)->description('Website of the company, to match it by domain or give it to a new one.'),
            'person' => $schema->object()->description('{first_name, last_name, email, mobile, job_title}'),
            'owner_id' => $schema->string(),
            'amount_once' => $schema->number()->min(0),
            'recurring' => $schema->number()->min(0),
            'recurring_period' => $schema->string()->enum(['monthly', 'yearly']),
            'expected_close_date' => $schema->string()->description('Y-m-d'),
            'priority' => $schema->string()->enum(['p1', 'p2', 'p3', 'p4', 'excluded']),
            'source' => $schema->string(),
            'next_step' => $schema->string(),
            'external_ref' => $schema->string()->max(64),
            'tags' => $schema->array()->items($schema->string()),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $data = $request->all();
        $workspace = null;

        if (isset($data['pipeline_id'])) {
            $workspace = Pipeline::query()->visibleTo($user)->findOrFail((int) $data['pipeline_id'])->workspaceRef();
        } elseif ($access->resolver()->enabled()) {
            $workspace = Workspace::fromKey(is_string($data['workspace'] ?? null) ? $data['workspace'] : null) ?? ($access->workspacesFor($user)[0] ?? null);
        }

        unset($data['workspace']);
        $deal = app(CreateDeal::class)->handle($workspace, $data, $user);

        return $this->json(['message' => 'Deal created.', 'deal' => $this->summary($deal->fresh() ?? $deal, $access)]);
    }
}
