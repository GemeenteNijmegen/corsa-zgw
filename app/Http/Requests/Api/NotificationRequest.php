<?php

namespace App\Http\Requests\Api;

use App\Rules\ConfiguredZgwHost;
use Illuminate\Foundation\Http\FormRequest;

class NotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Every kanaal and resource is accepted: a well-formed notification that this
        // application does not process is acknowledged and stored as ignored, so the
        // sender does not keep retrying it. The actie is limited to the actions the
        // ZGW APIs publish, including partial_update for a PATCH.
        return [
            'actie' => 'required|string|in:create,update,partial_update,destroy',
            'kanaal' => 'required|string|max:255',
            'resource' => 'required|string|max:255',
            'hoofdObject' => ['bail', 'required', 'url', app(ConfiguredZgwHost::class)],
            'resourceUrl' => ['bail', 'required', 'url', app(ConfiguredZgwHost::class)],
            'aanmaakdatum' => 'required|date',
            'kenmerken' => 'sometimes|nullable|array',
        ];
    }
}
