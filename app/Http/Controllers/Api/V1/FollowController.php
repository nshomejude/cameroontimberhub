<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer/supplier following a Company or another User. Behind `auth:sanctum`
 * (routes/api.php). `type` is resolved against an explicit allow-list, never
 * a raw class-name lookup.
 *
 * Self-follow is rejected with 422 (not a silent no-op) — there is no
 * existing "cannot do X to yourself" guard elsewhere in this codebase to
 * mirror, so this is a straightforward direct check.
 */
class FollowController extends Controller
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const TYPES = [
        'company' => Company::class,
        'user' => User::class,
    ];

    public function store(Request $request): JsonResponse
    {
        $target = $this->resolveTarget($request);

        Follow::query()->firstOrCreate([
            'follower_id' => $request->user()->id,
            'followable_type' => $target::class,
            'followable_id' => $target->id,
        ]);

        return response()->json(['following' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $target = $this->resolveTarget($request);

        Follow::query()
            ->where('follower_id', $request->user()->id)
            ->where('followable_type', $target::class)
            ->where('followable_id', $target->id)
            ->delete();

        return response()->json(['following' => false]);
    }

    /**
     * Who the caller follows. Each row is shaped `{id, type, name, slug,
     * logo_url|avatar_url, followed_at}` — a company row carries `slug` +
     * `logo_url`; a user row carries neither `slug` nor `avatar_url` (the
     * `User` model has no avatar column, so that field is always `null`
     * rather than an invented one).
     */
    public function following(Request $request): JsonResponse
    {
        return $this->followList(
            Follow::query()->where('follower_id', $request->user()->id)->orderByDesc('id'),
            $request,
        );
    }

    /**
     * Who follows the caller. Scope: the caller's own User row's followers
     * PLUS, if the caller belongs to a company, that company's followers
     * (a person can be followed both as a user and, via their company, as a
     * company — this returns the union of both, which is the simplest
     * correct interpretation of "who follows me" for a caller who may wear
     * both hats).
     */
    public function followers(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyIds = $user->companies()->pluck('companies.id');

        $query = Follow::query()->where(function ($q) use ($user, $companyIds) {
            $q->where(fn ($q2) => $q2->where('followable_type', User::class)->where('followable_id', $user->id));

            if ($companyIds->isNotEmpty()) {
                $q->orWhere(fn ($q2) => $q2->where('followable_type', Company::class)->whereIn('followable_id', $companyIds));
            }
        })->orderByDesc('id');

        return $this->followerList($query, $request);
    }

    private function followList($query, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $follows = $query->paginate($perPage)->withQueryString();

        $follows->getCollection()->transform(fn (Follow $f) => $this->shapeFollowable($f->followable, $f->created_at));

        return response()->json($follows);
    }

    private function followerList($query, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $follows = $query->with('follower')->paginate($perPage)->withQueryString();

        $follows->getCollection()->transform(fn (Follow $f) => $this->shapeFollowable($f->follower, $f->created_at));

        return response()->json($follows);
    }

    /** @return array<string, mixed> */
    private function shapeFollowable(Company|User|null $subject, $followedAt): array
    {
        if ($subject instanceof Company) {
            return [
                'id' => $subject->id,
                'type' => 'company',
                'name' => $subject->name,
                'slug' => $subject->slug,
                'logo_url' => $subject->logoUrl(),
                'followed_at' => $followedAt?->toIso8601String(),
            ];
        }

        return [
            'id' => $subject?->id,
            'type' => 'user',
            'name' => $subject?->name,
            'slug' => null,
            'avatar_url' => null,
            'followed_at' => $followedAt?->toIso8601String(),
        ];
    }

    private function resolveTarget(Request $request): Company|User
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:company,user'],
            'id' => ['required', 'integer'],
        ]);
        $data = $validator->validate();

        $modelClass = self::TYPES[$data['type']];

        if ($modelClass === User::class && (int) $data['id'] === $request->user()->id) {
            throw ValidationException::withMessages(['id' => ['You cannot follow yourself.']]);
        }

        $target = $modelClass::query()->find($data['id']);

        if (! $target) {
            throw new NotFoundHttpException('The selected item does not exist.');
        }

        return $target;
    }
}
