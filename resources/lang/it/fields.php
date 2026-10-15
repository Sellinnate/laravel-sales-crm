<?php

return [
    'deal' => [
        'title' => 'Titolo', 'company_id' => 'Azienda', 'company_name' => 'Azienda', 'company_vat' => 'Partita IVA dell\'azienda', 'company_website' => 'Sito dell\'azienda', 'owner_id' => 'Owner', 'priority' => 'Priorità',
        'source' => 'Fonte', 'tags' => 'Tag', 'external_ref' => 'Riferimento esterno', 'amount_once' => 'Importo una tantum',
        'amount_once_cents' => 'Importo una tantum', 'deposit' => 'Acconto', 'deposit_cents' => 'Acconto', 'recurring' => 'Importo ricorrente',
        'recurring_cents' => 'Importo ricorrente', 'recurring_period' => 'Periodicità', 'recurring_options' => 'Ricorrenti e opzioni',
        'payment_terms' => 'Condizioni di pagamento', 'offer_details' => 'Dettagli dell\'offerta', 'offer_valid_until' => 'Validità dell\'offerta',
        'expected_close_date' => 'Chiusura prevista', 'sub_status' => 'Sotto-stato', 'next_step' => 'Prossimo passo', 'blocker' => 'Blocco o dubbio del cliente',
        'missing_data' => 'Dati mancanti', 'call_context' => 'Contesto per la chiamata', 'closing_tip' => 'Consiglio di chiusura', 'approval_required' => 'Approvazione richiesta',
        'approval_status' => 'Esito dell\'approvazione', 'approver_id' => 'Approvatore', 'approval_note' => 'Cosa approvare', 'signature_status' => 'Firma',
        'signature_evidence' => 'Evidenza della firma', 'payment_status' => 'Incasso', 'amount_collected' => 'Importo incassato',
        'amount_collected_cents' => 'Importo incassato', 'payment_evidence' => 'Evidenza dell\'incasso', 'commission_type' => 'Tipo di compenso',
        'commission_value' => 'Compenso', 'commission_note' => 'Nota sul compenso', 'lost_reason' => 'Motivo di perdita', 'lost_note' => 'Nota sulla perdita',
        'last_contacted_at' => 'Ultimo contatto', 'data_verified_at' => 'Ultima verifica dei dati', 'stage_id' => 'Fase', 'pipeline_id' => 'Pipeline',
    ],
    'activity' => [
        'type' => 'Tipo', 'title' => 'Titolo', 'due_date' => 'Data', 'due_time' => 'Ora', 'remind_minutes' => 'Promemoria',
        'assigned_to' => 'Assegnatario', 'notes' => 'Note', 'person_id' => 'Persona', 'outcome' => 'Esito',
    ],
    'person' => [
        'person_id' => 'Persona', 'first_name' => 'Nome', 'last_name' => 'Cognome', 'job_title' => 'Ruolo in azienda', 'email' => 'Email',
        'mobile' => 'Cellulare', 'phone' => 'Telefono', 'phone_source' => 'Fonte del recapito', 'role' => 'Ruolo nella trattativa', 'company_id' => 'Azienda', 'notes' => 'Note',
    ],
    'company' => [
        'name' => 'Nome', 'legal_name' => 'Ragione sociale', 'vat_number' => 'Partita IVA', 'website' => 'Sito', 'email' => 'Email', 'phone' => 'Telefono',
        'address' => 'Indirizzo', 'city' => 'Città', 'region' => 'Provincia o regione', 'postal_code' => 'CAP', 'country' => 'Paese', 'industry' => 'Settore', 'notes' => 'Note',
    ],
    'document' => ['file' => 'Documento', 'kind' => 'Tipo di documento', 'document_date' => 'Data del documento', 'valid_until' => 'Valido fino al'],
    'email' => ['to' => 'A', 'cc' => 'Cc', 'subject' => 'Oggetto', 'body' => 'Testo'],
    'pipeline' => ['name' => 'Nome', 'is_active' => 'Attiva'],
    'stage' => [
        'name' => 'Nome', 'color' => 'Colore', 'probability' => 'Probabilità', 'kind' => 'Tipo', 'rotting_days' => 'Ferma dopo (giorni)',
        'required_fields' => 'Campi obbligatori', 'automation_type' => 'Tipo dell\'attività automatica', 'automation_title' => 'Titolo dell\'attività automatica',
        'automation_days' => 'Scadenza dell\'attività automatica (giorni)',
    ],
    'custom' => ['key' => 'Chiave', 'label' => 'Nome', 'type' => 'Tipo', 'options' => 'Opzioni'],
    'template' => ['name' => 'Nome', 'subject' => 'Oggetto', 'body' => 'Testo'],
    'view' => ['name' => 'Nome della vista'],
];
