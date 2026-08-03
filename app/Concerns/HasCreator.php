<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Captures the creating user in `created_by` on models needing creator attribution
 * (Proxy first; reusable — ADR-009 Amendment A3).
 *
 * A structural twin of {@see BelongsToCurrentTeam}: a `creating` boot hook sets
 * `created_by` to the authenticated user's id **only** when the attribute is not
 * already set and `Auth::check()` is true. It never throws and never fabricates a
 * creator, so unauthenticated contexts (console, queue, seeders, token-authed
 * ingest) leave the column null — a safe deny for ownership-limited roles.
 *
 * @property int|null $created_by
 * @property-read User|null $creator
 */
trait HasCreator
{
    /**
     * Boot the trait: register the created_by auto-assignment on create.
     */
    public static function bootHasCreator(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('created_by')) && Auth::check()) {
                $model->setAttribute('created_by', Auth::id());
            }
        });
    }

    /**
     * The user who created this record, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
