<?php

namespace App\Http\Requests;

use App\Display\DisplayStateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisplayPlaybackRequest extends FormRequest
{
    /** Auth is handled by the poster.token middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('event'))) {
            $this->merge(['event' => strtolower(trim($this->input('event')))]);
        }
    }

    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::in(DisplayStateService::PLAYBACK_EVENTS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
