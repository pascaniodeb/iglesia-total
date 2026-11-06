<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Models\User;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Domain\Enums\ActivityChannel;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * Writes activity log entries to PostgreSQL. The hash chain is computed by the database
 * trigger; this adapter only decides who acted, through which channel and from where.
 */
final readonly class DatabaseActivityLog implements ActivityLog
{
    public function __construct(
        private AuthFactory $auth,
        private ActivityContext $context,
        private Application $app,
    ) {}

    public function record(string $action, string $summary, ?Model $subject = null, array $changes = []): void
    {
        $this->write(ActivityCategory::Data, $action, $summary, $subject, $changes);
    }

    public function security(string $action, string $summary, ?Model $subject = null, array $details = []): void
    {
        $this->write(ActivityCategory::Security, $action, $summary, $subject, $details);
    }

    public function access(string $action, string $summary, ?Model $subject = null, array $details = []): void
    {
        $this->write(ActivityCategory::Access, $action, $summary, $subject, $details);
    }

    public function system(string $action, string $summary, ?Model $subject = null, array $details = []): void
    {
        $this->write(ActivityCategory::System, $action, $summary, $subject, $details);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function write(ActivityCategory $category, string $action, string $summary, ?Model $subject, array $changes): void
    {
        $actor = $this->auth->guard()->user();
        $actor = $actor instanceof User ? $actor : null;
        $channel = $this->channel($category, $actor);

        ActivityLogEntry::query()->create([
            'category' => $category,
            'channel' => $channel,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->role->value,
            'action' => $action,
            'subject_type' => $subject?->getTable(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'summary' => Str::limit($summary, 497),
            // An empty PHP array would be stored as a JSON list; the column holds an object.
            'changes' => $changes === [] ? new stdClass : $changes,
            'request_id' => $this->context->requestId,
            'ip' => $this->context->ip,
            'user_agent' => $this->context->userAgent,
            'method' => $this->context->method,
            'route' => $this->context->route,
        ]);
    }

    /**
     * A data write always has an author: a signed-in user or the operator at the console.
     * Only security facts (a failed sign-in) may come from someone not signed in.
     */
    private function channel(ActivityCategory $category, ?User $actor): ActivityChannel
    {
        if ($actor !== null) {
            return ActivityChannel::Web;
        }

        if ($this->app->runningInConsole()) {
            return ActivityChannel::System;
        }

        if ($category === ActivityCategory::Security) {
            return ActivityChannel::Guest;
        }

        throw new LogicException("Activity [{$category->value}] cannot be recorded without an actor.");
    }
}
