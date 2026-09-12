<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Scopes a model to a single owning user.
 *
 * Every user-owned model (accounts, transactions, categories, subscriptions)
 * must use this trait and carry a `user_id` column. Combined with the
 * `forUser` scope it guarantees a user only ever reads and writes their own
 * records. There is no admin role in this application, so ownership is the
 * only authorization boundary there is.
 */
trait OwnedByUser
{
    /**
     * Apply an owner scope and reject attempts to assign another owner.
     */
    protected static function bootOwnedByUser(): void
    {
        static::addGlobalScope('owned-by-user', function (Builder $builder): void {
            $userId = Auth::id();

            if ($userId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('user_id'), $userId);
        });

        static::creating(function (Model $model): void {
            $ownerId = $model->getAttribute('user_id');

            if ($ownerId === null) {
                if (! Auth::check()) {
                    throw new LogicException('An owner is required to create an owned record.');
                }

                $model->setAttribute('user_id', Auth::id());

                return;
            }

            if (Auth::check() && (int) $ownerId !== (int) Auth::id()) {
                throw new LogicException('A user cannot create a record for another user.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('user_id')) {
                throw new LogicException('The owner of an owned record cannot be changed.');
            }
        });
    }

    /**
     * The user that owns this record.
     */
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to records owned by the given user.
     *
     * @param  Builder<Model>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        if (Auth::check() && (int) Auth::id() !== (int) $user->getKey()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($this->qualifyColumn('user_id'), $user->getKey());

        if (! Auth::check()) {
            $query->withoutGlobalScope('owned-by-user');
        }
    }

    /**
     * Determine if the given user owns this record.
     */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->getAttribute('user_id') === (int) $user->getKey();
    }

    /**
     * Create a record owned by the given user.
     */
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createForUser(User $user, array $attributes = []): static
    {
        $attributes['user_id'] = $user->getKey();

        $model = static::query()->newModelInstance($attributes);
        $model->save();

        return $model;
    }
}
