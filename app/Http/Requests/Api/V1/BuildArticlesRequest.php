<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuildArticlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'required', 'string', Rule::in(['es', 'en'])],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))->status(400);
    }
}
