<?php

use Guardian\Attributes\GuardianAllowsSensitive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class AllowedPlayer extends Model
{
    protected $hidden = ['phone'];
}

class AllowedPlayerResource extends JsonResource
{
    #[GuardianAllowsSensitive(fields: ['phone'], reason: 'The authenticated account owner explicitly requested their profile phone number.')]
    public function toArray($request): array
    {
        return ['phone' => $this->phone];
    }
}
