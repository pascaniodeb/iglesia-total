<?php

namespace App\Modules\Members;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Infrastructure\DatabaseMemberDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Members module: the directory other modules read members through.
 */
class MembersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MemberDirectory::class, DatabaseMemberDirectory::class);
    }
}
