<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseApiRequest extends FormRequest
{
    /**
     * Override failedValidation to return RFC 7807-compliant error responses.
     */
    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors()->toArray();
        $firstError = collect($errors)->flatten()->first();

        throw new HttpResponseException(
            response()->json([
                'type' => 'VALIDATION_ERROR',
                'title' => 'Erreur de validation',
                'status' => 422,
                'detail' => $firstError ?? 'Les données fournies sont invalides.',
                'errors' => $errors,
                'request_id' => $this->header('X-Request-Id', ''),
            ], 422)
        );
    }

    /**
     * Handle failed authorization.
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'type' => 'FORBIDDEN',
                'title' => 'Action non autorisée',
                'status' => 403,
                'detail' => 'Vous n\'avez pas les droits nécessaires pour cette action.',
                'request_id' => $this->header('X-Request-Id', ''),
            ], 403)
        );
    }
}
