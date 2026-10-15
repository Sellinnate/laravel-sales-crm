<?php

return [
    'activity_type' => ['call' => 'Call', 'email' => 'Email', 'meeting' => 'Meeting', 'deadline' => 'Deadline', 'task' => 'Task'],
    'approval_status' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'],
    'commission_type' => ['amount' => 'Fixed amount', 'percent' => 'Percentage'],
    'deal_event_type' => [
        'created' => 'Deal created', 'stage_changed' => 'Stage changed', 'updated' => 'Change',
        'won' => 'Deal won', 'lost' => 'Deal lost', 'reopened' => 'Deal reopened',
    ],
    'deal_source' => [
        'inbound' => 'Inbound', 'outbound' => 'Outbound', 'referral' => 'Referral', 'email' => 'Email', 'event' => 'Event',
        'partner' => 'Partner', 'website' => 'Website', 'integration' => 'Integration', 'import' => 'Import', 'other' => 'Other',
    ],
    'email_direction' => ['out' => 'Sent', 'in' => 'Received'],
    'email_status' => ['queued' => 'Sending', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Not sent', 'received' => 'Received'],
    'document_kind' => [
        'quote' => 'Quote', 'contract' => 'Contract', 'signature_evidence' => 'Signature evidence',
        'payment_evidence' => 'Payment evidence', 'other' => 'Other',
    ],
    'import_status' => ['uploaded' => 'Uploaded', 'mapped' => 'Ready', 'running' => 'Running', 'done' => 'Completed', 'failed' => 'Failed'],
    'lost_reason' => [
        'price' => 'Price', 'timing' => 'Timing', 'competitor' => 'Competitor', 'no_response' => 'No response',
        'no_budget' => 'No budget', 'not_in_target' => 'Not a fit', 'postponed' => 'Postponed', 'other' => 'Other',
    ],
    'payment_status' => ['not_verified' => 'Not verified', 'expected' => 'Expected', 'partial' => 'Partial', 'paid' => 'Paid'],
    'person_role' => ['decision_maker' => 'Decision maker', 'influencer' => 'Influencer', 'operational' => 'Day-to-day contact', 'other' => 'Other'],
    'priority' => ['p1' => 'P1', 'p2' => 'P2', 'p3' => 'P3', 'p4' => 'P4', 'excluded' => 'Excluded'],
    'recurring_period' => ['monthly' => 'Monthly', 'yearly' => 'Yearly'],
    'recurring_period_suffix' => ['monthly' => '/mo', 'yearly' => '/yr'],
    'required_field' => [
        'company' => 'Company', 'person' => 'Contact person', 'owner' => 'Owner', 'amount' => 'Amount (one-off or recurring)',
        'expected_close_date' => 'Expected close date', 'offer_valid_until' => 'Offer valid until', 'payment_terms' => 'Payment terms',
        'next_activity' => 'Next activity planned', 'offer_document' => 'Quote attached',
    ],
    'signature_status' => ['not_verified' => 'Not verified', 'to_sign' => 'To sign', 'signed' => 'Signed'],
    'stage_color' => ['neutral' => 'Grey', 'brand' => 'Teal', 'info' => 'Blue', 'accent' => 'Coral', 'warning' => 'Amber', 'success' => 'Green', 'danger' => 'Red'],
    'stage_kind' => ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost'],
];
