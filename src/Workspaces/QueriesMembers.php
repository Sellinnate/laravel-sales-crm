<?php

namespace Sellinnate\SalesCrm\Workspaces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The users of the CRM, restricted by the optional local scope `sales-crm.users.scope` of the user
 * model (for example `salesTeam`), ordered by name.
 */
trait QueriesMembers
{
    /**
     * @return Builder<Model>
     */
    protected function usersQuery(): Builder
    {
        $model = Crm::userModel();
        /** @var Builder<Model> $query */
        $query = $model::query();
        $scope = config('sales-crm.users.scope');

        if (is_string($scope) && $scope !== '') {
            $query->{$scope}();
        }

        return $query->orderBy($query->getModel()->qualifyColumn(Crm::nameColumn()));
    }
}
