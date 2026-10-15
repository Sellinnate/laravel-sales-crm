<?php

namespace Sellinnate\SalesCrm\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Sellinnate\SalesCrm\CrmScope for(\Illuminate\Database\Eloquent\Model|\Sellinnate\SalesCrm\Workspaces\Workspace|null $workspace)
 * @method static \Sellinnate\SalesCrm\CrmScope shared()
 * @method static \Sellinnate\SalesCrm\Models\Deal updateDeal(\Sellinnate\SalesCrm\Models\Deal $deal, array<string, mixed> $data, \Illuminate\Contracts\Auth\Authenticatable $by)
 * @method static \Sellinnate\SalesCrm\Models\Deal moveDeal(\Sellinnate\SalesCrm\Models\Deal $deal, \Sellinnate\SalesCrm\Models\Stage|int $stage, \Illuminate\Contracts\Auth\Authenticatable $by, array<string, mixed> $fill = [], ?int $afterId = null)
 * @method static void deleteDeal(\Sellinnate\SalesCrm\Models\Deal $deal, \Illuminate\Contracts\Auth\Authenticatable $by)
 * @method static \Sellinnate\SalesCrm\Models\Person addPerson(\Sellinnate\SalesCrm\Models\Deal $deal, array<string, mixed> $data, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Sellinnate\SalesCrm\Models\Note addNote(\Sellinnate\SalesCrm\Models\Deal|\Sellinnate\SalesCrm\Models\Company|\Sellinnate\SalesCrm\Models\Person $target, string $body, \Illuminate\Contracts\Auth\Authenticatable $by, bool $pinned = false)
 * @method static \Sellinnate\SalesCrm\Models\Activity scheduleActivity(\Sellinnate\SalesCrm\Models\Deal|\Sellinnate\SalesCrm\Models\Company|\Sellinnate\SalesCrm\Models\Person $target, array<string, mixed> $data, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Sellinnate\SalesCrm\Models\Activity completeActivity(\Sellinnate\SalesCrm\Models\Activity $activity, ?string $outcome, \Illuminate\Contracts\Auth\Authenticatable $by)
 * @method static \Sellinnate\SalesCrm\Models\Email sendEmail(\Sellinnate\SalesCrm\Models\Deal $deal, array<string, mixed> $data, \Illuminate\Contracts\Auth\Authenticatable $by)
 * @method static \Sellinnate\SalesCrm\Models\Document uploadDocument(\Sellinnate\SalesCrm\Models\Deal|\Sellinnate\SalesCrm\Models\Company $target, \Illuminate\Http\UploadedFile $file, array<string, mixed> $data, \Illuminate\Contracts\Auth\Authenticatable $by)
 * @method static \Sellinnate\SalesCrm\Authorization\Access access()
 * @method static \Sellinnate\SalesCrm\Services\DealBoard board()
 * @method static \Sellinnate\SalesCrm\Services\Reports reports()
 * @method static \Sellinnate\SalesCrm\Services\DealTimeline timeline()
 * @method static \Sellinnate\SalesCrm\Inbound\Inbound inbound()
 *
 * @see \Sellinnate\SalesCrm\SalesCrm
 */
class SalesCrm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sellinnate\SalesCrm\SalesCrm::class;
    }
}
