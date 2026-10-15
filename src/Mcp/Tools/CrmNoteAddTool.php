<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;

class CrmNoteAddTool extends CrmTool
{
    protected string $name = 'crm_note_add';

    protected string $description = 'Adds a note to a deal. Colleagues mentioned as @Full Name are notified.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()->min(1)->required(),
            'body' => $schema->string()->max(5000)->required(),
            'pinned' => $schema->boolean(),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $validated = $request->validate(['deal_id' => ['required', 'integer', 'min:1'], 'body' => ['required', 'string', 'max:5000'], 'pinned' => ['nullable', 'boolean']]);
        $deal = Deal::query()->visibleTo($user)->findOrFail((int) $validated['deal_id']);
        $note = app(ManageNotes::class)->add($deal, (string) $validated['body'], $user, (bool) ($validated['pinned'] ?? false));

        return $this->json(['message' => 'Note added.', 'note_id' => $note->id]);
    }
}
