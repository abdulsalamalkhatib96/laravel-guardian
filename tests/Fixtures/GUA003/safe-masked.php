<?php

namespace Fixtures\GUA003\SafeMaskedCase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

final class Player extends Model
{
    protected $hidden = ['phone'];
}

final class PlayerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'phone' => Str::mask($this->phone, '*', 3),
        ];
    }
}
