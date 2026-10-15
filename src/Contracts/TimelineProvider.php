<?php

namespace Sellinnate\SalesCrm\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;

/**
 * Read-only entries of the host mixed into the timeline of deals, companies and people (calls of
 * your telephony, support tickets, orders…). Register the class in `sales-crm.timeline.providers`.
 */
interface TimelineProvider
{
    /**
     * The key of the filter of these entries (letters, digits, dashes).
     */
    public function kind(): string;

    /**
     * The label of the filter.
     */
    public function label(): string;

    /**
     * The most recent entries of the subject the viewer may read.
     *
     * @return iterable<TimelineEntry>
     */
    public function entries(Deal|Company|Person $subject, ?Authenticatable $viewer, int $limit): iterable;

    /**
     * How many entries the subject has for the viewer (the counter of the filter).
     */
    public function count(Deal|Company|Person $subject, ?Authenticatable $viewer): int;
}
