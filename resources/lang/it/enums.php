<?php

return [
    'activity_type' => ['call' => 'Chiamata', 'email' => 'Email', 'meeting' => 'Incontro', 'deadline' => 'Scadenza', 'task' => 'Attività'],
    'approval_status' => ['pending' => 'In attesa', 'approved' => 'Approvata', 'rejected' => 'Respinta'],
    'commission_type' => ['amount' => 'Importo fisso', 'percent' => 'Percentuale'],
    'deal_event_type' => [
        'created' => 'Trattativa creata', 'stage_changed' => 'Cambio di fase', 'updated' => 'Modifica',
        'won' => 'Trattativa vinta', 'lost' => 'Trattativa persa', 'reopened' => 'Trattativa riaperta',
    ],
    'deal_source' => [
        'inbound' => 'Inbound', 'outbound' => 'Outbound', 'referral' => 'Referral', 'email' => 'Email', 'event' => 'Evento',
        'partner' => 'Partner', 'website' => 'Sito web', 'integration' => 'Integrazione', 'import' => 'Import', 'other' => 'Altro',
    ],
    'email_direction' => ['out' => 'Inviata', 'in' => 'Ricevuta'],
    'email_status' => ['queued' => 'In invio', 'sending' => 'In invio', 'sent' => 'Inviata', 'failed' => 'Non inviata', 'received' => 'Ricevuta'],
    'document_kind' => [
        'quote' => 'Preventivo', 'contract' => 'Contratto', 'signature_evidence' => 'Evidenza di firma',
        'payment_evidence' => 'Evidenza di incasso', 'other' => 'Altro',
    ],
    'import_status' => ['uploaded' => 'Caricato', 'mapped' => 'Pronto', 'running' => 'In corso', 'done' => 'Completato', 'failed' => 'Non riuscito'],
    'lost_reason' => [
        'price' => 'Prezzo', 'timing' => 'Tempistiche', 'competitor' => 'Concorrente', 'no_response' => 'Nessuna risposta',
        'no_budget' => 'Nessun budget', 'not_in_target' => 'Non in target', 'postponed' => 'Rinviata', 'other' => 'Altro',
    ],
    'payment_status' => ['not_verified' => 'Non verificato', 'expected' => 'Atteso', 'partial' => 'Parziale', 'paid' => 'Incassato'],
    'person_role' => ['decision_maker' => 'Decisore', 'influencer' => 'Influenzatore', 'operational' => 'Referente operativo', 'other' => 'Altro'],
    'priority' => ['p1' => 'P1', 'p2' => 'P2', 'p3' => 'P3', 'p4' => 'P4', 'excluded' => 'Escluso'],
    'recurring_period' => ['monthly' => 'Mensile', 'yearly' => 'Annuale'],
    'recurring_period_suffix' => ['monthly' => '/mese', 'yearly' => '/anno'],
    'required_field' => [
        'company' => 'Azienda', 'person' => 'Referente', 'owner' => 'Owner', 'amount' => 'Importo (una tantum o ricorrente)',
        'expected_close_date' => 'Data di chiusura prevista', 'offer_valid_until' => 'Validità dell\'offerta', 'payment_terms' => 'Condizioni di pagamento',
        'next_activity' => 'Prossima attività pianificata', 'offer_document' => 'Preventivo allegato',
    ],
    'signature_status' => ['not_verified' => 'Non verificata', 'to_sign' => 'Da firmare', 'signed' => 'Firmata'],
    'stage_color' => ['neutral' => 'Grigio', 'brand' => 'Verde acqua', 'info' => 'Blu', 'accent' => 'Corallo', 'warning' => 'Ambra', 'success' => 'Verde', 'danger' => 'Rosso'],
    'stage_kind' => ['open' => 'Aperta', 'won' => 'Vinta', 'lost' => 'Persa'],
];
