<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\DealPresenter;

/**
 * The base of the MCP tools of the CRM: they run for the authenticated user of the MCP request, with
 * the same scope and rules as the UI (the actions authorize every write), and turn validation,
 * authorization and not-found errors into tool errors the agent can act on.
 */
abstract class CrmTool extends Tool
{
    abstract protected function perform(Request $request, Authenticatable $user, Access $access): Response;

    public function handle(Request $request, Access $access): Response
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable || ! $access->canAccess($user)) {
            return Response::error('You are not allowed to use the CRM.');
        }

        try {
            return $this->perform($request, $user, $access);
        } catch (ValidationException $exception) {
            return Response::error(implode(' ', array_map(fn (string $key, array $messages): string => $key.': '.implode(' ', $messages), array_keys($exception->errors()), $exception->errors())));
        } catch (AuthorizationException) {
            return Response::error('You are not allowed to do this on this record.');
        } catch (ModelNotFoundException) {
            return Response::error('Record not found (or not visible to you).');
        }
    }

    /**
     * A deal as the tools present it.
     *
     * @return array<string, mixed>
     */
    protected function summary(Deal $deal, Access $access): array
    {
        return DealPresenter::present($deal, $access);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::json($data);
    }
}
