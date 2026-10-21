<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait HandlesConnectedProjectErrors
{
    /** Keep provider, database and filesystem diagnostics out of tool results. */
    private function connectedResponse(callable $operation): Response
    {
        try {
            return Response::json($operation());
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        } catch (ModelNotFoundException) {
            return Response::error('The document or import is unavailable in this connection.');
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return Response::error('This operation is unavailable, expired, or no longer authorized.');
            }
            report($exception);
            return Response::error('AskMyDocs could not complete this operation. Check its status before retrying.');
        }
    }
}
