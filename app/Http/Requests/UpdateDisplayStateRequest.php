<?php

namespace App\Http\Requests;

use App\Display\DisplayStateService;
use App\Models\DisplayState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDisplayStateRequest extends FormRequest
{
    /** Auth is handled by the poster.token middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mode'))) {
            $this->merge(['mode' => strtolower(trim($this->input('mode')))]);
        }
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(DisplayState::MODES)],
            'brightness' => ['nullable', 'integer', 'between:0,100'],
            'fade_ms' => ['nullable', 'integer', 'between:0,'.DisplayStateService::MAX_FADE_MS],
            'reason' => ['nullable', 'string', 'max:255'],
            // 0 = never expire; omitted = POSTER_AUTO_RESTORE_MINUTES.
            'expires_in_minutes' => ['nullable', 'integer', 'between:0,525600'],
        ];
    }
}
