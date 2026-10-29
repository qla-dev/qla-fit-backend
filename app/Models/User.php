<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'apple_id',
        'ai_coins',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** Today's free MarkAI replies: the daily allowance and how much is used. */
    public function freeMessages(): array
    {
        $used = DB::table('markai_messages')->where('user_id', $this->id)
            ->where('free', true)->where('status', '!=', 'failed')
            ->where('created_at', '>=', now()->utc()->startOfDay())->count();

        return ['limit' => (int) config('fitness.markai_free_daily'), 'used' => $used];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'training_until' => 'datetime',
        ];
    }
}
