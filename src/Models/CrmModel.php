<?php

namespace Sellinnate\SalesCrm\Models;

use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The base of the CRM models: the table takes the configured prefix and the connection of
 * `sales-crm.database.connection`.
 */
abstract class CrmModel extends Model
{
    /**
     * The table name without the prefix.
     */
    protected string $baseTable = '';

    public function getTable(): string
    {
        return Crm::table($this->baseTable);
    }

    public function getConnectionName(): ?string
    {
        return Crm::connection() ?? parent::getConnectionName();
    }
}
