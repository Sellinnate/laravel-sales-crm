<?php

return [
    'deal' => [
        'title' => 'Title', 'company_id' => 'Company', 'company_name' => 'Company', 'owner_id' => 'Owner', 'priority' => 'Priority',
        'source' => 'Source', 'tags' => 'Tags', 'external_ref' => 'External reference', 'amount_once' => 'One-off amount',
        'amount_once_cents' => 'One-off amount', 'deposit' => 'Deposit', 'deposit_cents' => 'Deposit', 'recurring' => 'Recurring amount',
        'recurring_cents' => 'Recurring amount', 'recurring_period' => 'Billing period', 'recurring_options' => 'Recurring and options',
        'payment_terms' => 'Payment terms', 'offer_details' => 'Offer details', 'offer_valid_until' => 'Offer valid until',
        'expected_close_date' => 'Expected close date', 'sub_status' => 'Sub-status', 'next_step' => 'Next step', 'blocker' => 'Customer blocker',
        'missing_data' => 'Missing data', 'call_context' => 'Call context', 'closing_tip' => 'Closing tip', 'approval_required' => 'Approval required',
        'approval_status' => 'Approval outcome', 'approver_id' => 'Approver', 'approval_note' => 'What to approve', 'signature_status' => 'Signature',
        'signature_evidence' => 'Signature evidence', 'payment_status' => 'Payment', 'amount_collected' => 'Amount collected',
        'amount_collected_cents' => 'Amount collected', 'payment_evidence' => 'Payment evidence', 'commission_type' => 'Commission type',
        'commission_value' => 'Commission', 'commission_note' => 'Commission note', 'lost_reason' => 'Lost reason', 'lost_note' => 'Lost note',
        'last_contacted_at' => 'Last contact', 'data_verified_at' => 'Data last verified', 'stage_id' => 'Stage', 'pipeline_id' => 'Pipeline',
    ],
    'activity' => [
        'type' => 'Type', 'title' => 'Title', 'due_date' => 'Date', 'due_time' => 'Time', 'remind_minutes' => 'Reminder',
        'assigned_to' => 'Assignee', 'notes' => 'Notes', 'person_id' => 'Person', 'outcome' => 'Outcome',
    ],
    'person' => [
        'person_id' => 'Person', 'first_name' => 'First name', 'last_name' => 'Last name', 'job_title' => 'Job title', 'email' => 'Email',
        'mobile' => 'Mobile', 'phone' => 'Phone', 'phone_source' => 'Contact source', 'role' => 'Role in the deal', 'company_id' => 'Company', 'notes' => 'Notes',
    ],
    'company' => [
        'name' => 'Name', 'legal_name' => 'Legal name', 'vat_number' => 'VAT number', 'website' => 'Website', 'email' => 'Email', 'phone' => 'Phone',
        'address' => 'Address', 'city' => 'City', 'region' => 'Region', 'postal_code' => 'Postal code', 'country' => 'Country', 'industry' => 'Industry', 'notes' => 'Notes',
    ],
    'document' => ['file' => 'Document', 'kind' => 'Document type', 'document_date' => 'Document date', 'valid_until' => 'Valid until'],
    'email' => ['to' => 'To', 'cc' => 'Cc', 'subject' => 'Subject', 'body' => 'Message'],
    'pipeline' => ['name' => 'Name', 'is_active' => 'Active'],
    'stage' => [
        'name' => 'Name', 'color' => 'Colour', 'probability' => 'Probability', 'kind' => 'Kind', 'rotting_days' => 'Stale after (days)',
        'required_fields' => 'Required fields', 'automation_type' => 'Automatic activity type', 'automation_title' => 'Automatic activity title',
        'automation_days' => 'Automatic activity due in (days)',
    ],
    'custom' => ['key' => 'Key', 'label' => 'Name', 'type' => 'Type', 'options' => 'Options'],
    'template' => ['name' => 'Name', 'subject' => 'Subject', 'body' => 'Text'],
    'view' => ['name' => 'View name'],
];
