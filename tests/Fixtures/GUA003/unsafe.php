<?php

namespace Fixtures\GUA003\UnsafeCase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

final class Player extends Model
{
    protected $hidden = ['phone', 'password'];
}

final class PlayerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
        ];
    }
}
