<?php

namespace Sellinnate\SalesCrm\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Models\Document;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the documents of the CRM: through the policy for users, and through a temporary signed
 * link for the quotes sent with `{{quote_link}}`.
 */
class DocumentController extends Controller
{
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return $this->stream($document);
    }

    public function signed(Document $document): StreamedResponse
    {
        abort_unless($document->kind === DocumentKind::Quote && $document->deal_id !== null, 404);

        return $this->stream($document);
    }

    private function stream(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
