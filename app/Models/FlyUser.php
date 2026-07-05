<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Str;

class FlyUser extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $table = 'fly_users';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'password',
        'user_type',
        'otp_verified_at',
        'is_locked',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'otp_verified_at' => 'datetime',
        'is_locked' => 'boolean',
    ];

    // Automate alphanumeric sequence generation strings
    protected static function booted()
    {
        static::creating(function ($user) {
            if (empty($user->user_id)) {
                if ($user->user_type === 'user') {
                    // Generates a random 7-character mixed string prefixed for buyers
                    $user->user_id = 'FYB-USR-' . strtoupper(Str::random(7));
                } else {
                    // Sequential logic matching administrative panels (FYB-ADM-001)
                    $adminCount = self::where('user_type', '!=', 'user')->count();
                    $user->user_id = 'FYB-ADM-' . str_pad($adminCount + 1, 3, '0', STR_PAD_LEFT);
                }
            }
        });
    }

    // Required JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    // Encrypt profiles natively into the access token payload
    public function getJWTCustomClaims()
    {
        return [
            'name'      => $this->name,
            'user_id'   => $this->user_id,
            'email'     => $this->email,
            'phone'     => $this->phone,
            'user_type' => $this->user_type
        ];
    }
}
