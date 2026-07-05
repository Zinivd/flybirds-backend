<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'user_name',
        'question',
        'customer_reply',
        'admin_reply',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(FlyUser::class, 'user_id', 'user_id');
    }
}
