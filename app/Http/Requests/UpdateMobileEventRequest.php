<?php

namespace App\Http\Requests;

/**
 * Editing a drive takes the create form whole, less the choice to publish:
 * publishing has its own route (MobileEventService::publish()).
 */
class UpdateMobileEventRequest extends StoreMobileEventRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['publish']);

        return $rules;
    }
}
