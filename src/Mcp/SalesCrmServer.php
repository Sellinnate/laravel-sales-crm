<?php

namespace Sellinnate\SalesCrm\Mcp;

use Laravel\Mcp\Server;
use Sellinnate\SalesCrm\Mcp\Tools\CrmActivitiesDueTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmActivityAddTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealCreateTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealGetTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealMoveTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealsListTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealUpdateTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmNoteAddTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmPipelinesTool;

/**
 * The MCP server of the CRM: the tools an AI agent uses to read and work the deals of the
 * authenticated user, with the same scope and rules as the UI.
 */
class SalesCrmServer extends Server
{
    protected string $name = 'Sales CRM';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Tools of a sales CRM (pipelines, stages, deals, notes and activities), acting as the authenticated user.
        Start with crm_pipelines to learn the stage ids, crm_deals_list to find deals and crm_deal_get for the details.
        Amounts are in major currency units. Moving a deal may require fields: the error lists them, pass them in `fill`.
        MARKDOWN;

    /**
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        CrmPipelinesTool::class,
        CrmDealsListTool::class,
        CrmDealGetTool::class,
        CrmDealCreateTool::class,
        CrmDealUpdateTool::class,
        CrmDealMoveTool::class,
        CrmNoteAddTool::class,
        CrmActivityAddTool::class,
        CrmActivitiesDueTool::class,
    ];
}
