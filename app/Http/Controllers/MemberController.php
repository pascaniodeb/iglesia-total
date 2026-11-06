<?php

namespace App\Http\Controllers;

use App\Actions\Members\DeleteMember;
use App\Actions\Members\RestoreMember;
use App\Actions\Members\SaveMember;
use App\Enums\MemberDocumentType;
use App\Enums\MemberEducationLevel;
use App\Enums\MemberMaritalStatus;
use App\Enums\MemberSex;
use App\Enums\MemberStatus;
use App\Enums\Permission;
use App\Http\Requests\MemberRequest;
use App\Http\Resources\MemberResource;
use App\Http\Tables\MemberListing;
use App\Http\Tables\TableQuery;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The members registry: people, not money. Permissions (members.view / members.manage) are
 * enforced by the routes.
 */
class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            MemberListing::sortable(),
            MemberListing::DEFAULT_SORT,
            MemberListing::facets(),
            defaultStatus: 'active',
        );

        $members = $table
            ->paginate(MemberListing::base($table), MemberListing::searchable())
            ->through(MemberListing::row(...));

        return Inertia::render('members/index', [
            'members' => $members,
            'filters' => $table->filters(),
            'statuses' => MemberStatus::options(),
            'sexes' => MemberSex::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('members/create', [
            'options' => $this->options(),
        ]);
    }

    public function store(MemberRequest $request, SaveMember $saveMember): RedirectResponse
    {
        $member = $saveMember(null, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Member :name registered.', ['name' => $member->full_name]),
        ]);

        return to_route('members.show', $member);
    }

    /**
     * The card of a member: editable for whoever manages members, read-only for the rest and
     * for a member in the trash.
     */
    public function show(Request $request, Member $member): Response
    {
        $member->load(['children', 'roles', 'spouse']);

        return Inertia::render('members/edit', [
            'member' => MemberResource::make($member)->resolve($request),
            'options' => $this->options(),
            'read_only' => $member->trashed() || $request->user()?->cannot(Permission::MembersManage->value),
        ]);
    }

    public function update(MemberRequest $request, Member $member, SaveMember $saveMember): RedirectResponse
    {
        $saveMember($member, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Member :name updated.', ['name' => $member->full_name]),
        ]);

        return to_route('members.show', $member);
    }

    public function destroy(Member $member, DeleteMember $deleteMember): RedirectResponse
    {
        try {
            $deleteMember($member);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => (string) collect($exception->errors())->flatten()->first(),
            ]);

            return to_route('members.show', $member);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Member :name moved to the trash.', ['name' => $member->full_name]),
        ]);

        return to_route('members.index');
    }

    public function restore(Member $member, RestoreMember $restoreMember): RedirectResponse
    {
        $restoreMember($member);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Member :name restored.', ['name' => $member->full_name]),
        ]);

        return to_route('members.show', $member);
    }

    /**
     * Members that can be chosen as a spouse: not in the trash, not the member being edited,
     * and either free or already their spouse. Searched on the server so the form does not load
     * the whole registry.
     */
    public function spouseOptions(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'except' => ['nullable', 'uuid'],
        ]);

        $except = $request->query('except');

        $members = Member::query()
            ->search((string) $request->query('search', ''))
            ->when(
                is_string($except),
                fn (Builder $query) => $query
                    ->whereKeyNot($except)
                    ->where(fn (Builder $query) => $query->whereNull('spouse_id')->orWhere('spouse_id', $except)),
                fn (Builder $query) => $query->whereNull('spouse_id'),
            )
            ->orderedByName()
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $members->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->full_name,
                'document' => MemberListing::document($member),
            ]),
        ]);
    }

    /**
     * The choices of the form's selects.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        return [
            'document_types' => MemberDocumentType::options(),
            'sexes' => MemberSex::options(),
            'education_levels' => MemberEducationLevel::options(),
            'marital_statuses' => MemberMaritalStatus::options(),
            'statuses' => MemberStatus::options(),
        ];
    }
}
