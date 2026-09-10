<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChannel extends Model
{
    protected $fillable = ['name', 'chat_id', 'is_active', 'rules', 'publish_chain_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function rule(string $key, mixed $default = null): mixed
    {
        return data_get($this->rules, $key, $default);
    }
}
