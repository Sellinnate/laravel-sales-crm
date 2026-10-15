<?php

namespace Sellinnate\SalesCrm\Mcp;

use Laravel\Mcp\Facades\Mcp;

/**
 * Registers the MCP server of the CRM at `sales-crm.mcp.path` when `sales-crm.mcp.enabled` is on and
 * laravel/mcp is installed.
 */
final class McpRegistrar
{
    public static function register(): void
    {
        Mcp::web('/'.trim((string) config('sales-crm.mcp.path', 'mcp/crm'), '/'), SalesCrmServer::class)
            ->middleware((array) config('sales-crm.mcp.middleware', ['auth:sanctum']));
    }
}
