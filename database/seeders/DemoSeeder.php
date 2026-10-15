<?php

namespace Sellinnate\SalesCrm\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\DealEventType;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Enums\Priority;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\DealEvent;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\StageVisit;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Fictitious demo data for the CRM (never in production): the default pipeline of the shared CRM
 * (or of every workspace of `$workspaces`) with about thirty deals across the stages, companies,
 * people, activities and notes. Owners are picked among the existing users of each workspace; no
 * user is created.
 */
class DemoSeeder extends Seeder
{
    /**
     * The workspaces to fill (null: the shared CRM).
     *
     * @var list<Model|null>
     */
    public array $workspaces = [null];

    private const COMPANIES = [
        ['Northwind Traders', 'northwind.example', 'Logistics', 'Milan'],
        ['Blue Harbor Studio', 'blueharbor.example', 'Design', 'Turin'],
        ['Greenfield Bakery', 'greenfield.example', 'Food', 'Bologna'],
        ['Orbital Robotics', 'orbital.example', 'Manufacturing', 'Bergamo'],
        ['Lumen Dental Clinic', 'lumendental.example', 'Healthcare', 'Rome'],
        ['Cedar & Stone Architects', 'cedarstone.example', 'Architecture', 'Florence'],
        ['Brightpath Academy', 'brightpath.example', 'Education', 'Padua'],
        ['Velvet Hotels', 'velvethotels.example', 'Hospitality', 'Venice'],
        ['Quartz Software', 'quartz.example', 'Software', 'Milan'],
        ['Seaside Fitness', 'seasidefit.example', 'Fitness', 'Genoa'],
        ['Atlas Insurance Brokers', 'atlasbrokers.example', 'Insurance', 'Naples'],
        ['Red Fern Winery', 'redfern.example', 'Wine', 'Verona'],
        ['Pixel Forge Games', 'pixelforge.example', 'Gaming', 'Turin'],
        ['Summit Engineering', 'summiteng.example', 'Engineering', 'Brescia'],
        ['Harborview Legal', 'harborview.example', 'Legal', 'Trieste'],
    ];

    private const PEOPLE = [
        ['Laura', 'Bianchi', 'CEO'], ['Marco', 'Rossi', 'Head of Operations'], ['Giulia', 'Conti', 'Marketing Manager'],
        ['Andrea', 'Ferri', 'CFO'], ['Sara', 'Galli', 'Office Manager'], ['Luca', 'Moretti', 'Founder'],
        ['Elena', 'Ricci', 'Procurement Lead'], ['Paolo', 'Greco', 'CTO'], ['Chiara', 'Lombardi', 'Sales Director'],
        ['Davide', 'Marino', 'General Manager'], ['Francesca', 'Costa', 'HR Manager'], ['Matteo', 'Fontana', 'Owner'],
        ['Valentina', 'Rinaldi', 'Head of Digital'], ['Simone', 'Caruso', 'Plant Manager'], ['Alessia', 'Ferrara', 'Partner'],
    ];

    private const DEALS = [
        'Website redesign', 'CRM rollout', 'Annual maintenance', 'Mobile app MVP', 'SEO and content plan', 'Booking platform',
        'E-commerce migration', 'Staff training program', 'Cloud hosting upgrade', 'Brand identity refresh', 'Customer portal',
        'Data analytics dashboard', 'Marketing automation', 'Support retainer', 'Online courses platform',
    ];

    public function run(): void
    {
        foreach ($this->workspaces as $workspace) {
            $this->seed(Workspace::of($workspace));
        }
    }

    private function seed(?Workspace $workspace): void
    {
        $pipeline = app(DefaultPipeline::class)->ensureFor($workspace);

        // Run again (a second install --demo), it adds nothing.
        if ($pipeline->deals()->exists()) {
            return;
        }

        $pipeline->load('stages');
        $owners = app(Access::class)->members($workspace)->limit(10)->get()->values();
        $matcher = app(Matcher::class);
        $now = CarbonImmutable::now();
        $priorities = [Priority::P1, Priority::P2, Priority::P2, Priority::P3, Priority::P3, Priority::P4, null];
        $sources = [DealSource::Inbound, DealSource::Referral, DealSource::Outbound, DealSource::Website, DealSource::Event, DealSource::Partner];
        $open = $pipeline->stages->filter(fn ($stage): bool => $stage->kind === StageKind::Open)->values();
        $won = $pipeline->stages->first(fn ($stage): bool => $stage->kind === StageKind::Won);
        $lost = $pipeline->stages->first(fn ($stage): bool => $stage->kind === StageKind::Lost);

        for ($index = 0; $index < 30; $index++) {
            [$companyName, $domain, $industry, $city] = self::COMPANIES[$index % count(self::COMPANIES)];
            [$first, $last, $title] = self::PEOPLE[$index % count(self::PEOPLE)];
            $company = $matcher->company($workspace, ['name' => $companyName, 'website' => 'https://www.'.$domain, 'industry' => $industry, 'city' => $city]);
            $person = $matcher->person($workspace, ['first_name' => $first, 'last_name' => $last, 'job_title' => $title, 'email' => strtolower($first).'@'.$domain, 'mobile' => '+39 333 '.str_pad((string) (1000000 + $index * 7919 % 9000000), 7, '0', STR_PAD_LEFT)], $company);

            $stage = match (true) {
                $index >= 27 => $lost,
                $index >= 23 => $won,
                default => $open[$index % $open->count()],
            };

            if ($stage === null) {
                continue;
            }

            $owner = $owners->isEmpty() ? null : $owners[$index % $owners->count()];
            $enteredAt = $now->subDays(($index * 3) % 25 + 1);
            $monthly = $index % 3 === 0;
            $deal = new Deal([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'position' => $index,
                'title' => self::DEALS[$index % count(self::DEALS)].($index >= count(self::DEALS) ? ' (phase 2)' : ''),
                'company_id' => $company->id,
                'owner_id' => Crm::userKey($owner),
                'priority' => $priorities[$index % count($priorities)],
                'source' => $sources[$index % count($sources)],
                'amount_once_cents' => (1500 + ($index * 1730) % 18000) * 100,
                'recurring_cents' => $monthly ? (90 + $index * 15) * 100 : null,
                'recurring_period' => $monthly ? RecurringPeriod::Monthly : null,
                'expected_close_date' => $now->addDays(($index * 11) % 150 - 20)->toDateString(),
                'offer_valid_until' => $stage->probability >= 60 && $stage->kind === StageKind::Open ? $now->addDays($index % 4 === 0 ? -3 : 12)->toDateString() : null,
                'next_step' => $stage->kind === StageKind::Open ? ['Send the revised quote', 'Book the demo with the team', 'Confirm the budget', 'Agree on the timeline'][$index % 4] : null,
                'blocker' => $index % 7 === 0 ? 'Waiting for the board approval of the budget' : null,
                'tags' => $index % 5 === 0 ? ['priority-account'] : null,
                'stage_entered_at' => $enteredAt,
                'last_activity_at' => $index % 6 === 0 ? $now->subDays(20) : $enteredAt,
                'won_at' => $stage->kind === StageKind::Won ? $enteredAt : null,
                'lost_at' => $stage->kind === StageKind::Lost ? $enteredAt : null,
                'lost_reason' => $stage->kind === StageKind::Lost ? [LostReason::Price, LostReason::Competitor, LostReason::NoBudget][$index % 3] : null,
                'created_by' => Crm::userKey($owner),
            ]);
            $deal->setWorkspace($workspace)->save();
            $deal->people()->attach($person->id, ['role' => 'decision_maker', 'is_primary' => true]);

            DealEvent::query()->create(['deal_id' => $deal->id, 'type' => DealEventType::Created, 'to' => $stage->name, 'created_at' => $enteredAt->subDays(2)]);
            StageVisit::query()->create(['deal_id' => $deal->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'stage_name' => $stage->name, 'entered_at' => $enteredAt]);

            if ($stage->kind === StageKind::Open && $index % 6 !== 0) {
                (new Activity([
                    'deal_id' => $deal->id,
                    'person_id' => $person->id,
                    'type' => [ActivityType::Call, ActivityType::Meeting, ActivityType::Email, ActivityType::Task][$index % 4],
                    'title' => ['Follow-up call', 'Demo meeting', 'Send the proposal', 'Prepare the contract'][$index % 4],
                    'due_at' => $now->addDays($index % 5 - 1)->setTime(9 + $index % 8, 0),
                    'has_time' => true,
                    'assigned_to' => Crm::userKey($owner),
                ]))->setWorkspace($workspace)->save();
            }

            if ($index % 4 === 0) {
                (new Note([
                    'deal_id' => $deal->id,
                    'user_id' => Crm::userKey($owner),
                    'body' => ['Very interested in the recurring plan; wants a discount on the first year.', 'They are comparing us with two other vendors.', 'Decision expected after the next board meeting.', 'Asked for references from similar projects.'][$index % 4],
                ]))->setWorkspace($workspace)->save();
            }

            app(DealActivityState::class)->refresh($deal->id);
        }

        Pipeline::query()->whereKey($pipeline->id)->increment('revision');
    }
}
