<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A team of the workbench app: the workspace of the multi-tenant tests.
 *
 * @property int $id
 * @property string $name
 */
class Team extends Model
{
    protected $fillable = ['name'];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user');
    }
}
