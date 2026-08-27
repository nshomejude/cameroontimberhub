<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.review') || $user->can('companies.manage');
    }

    /**
     * `Document::owner` is polymorphic -- it points at `Species` today and
     * will point at other owner types (carbon projects, vehicles, ...) later.
     * Unlike `CompanyDocumentPolicy::view()`, there is no single owner-relation
     * pattern to fall back to yet, so this stays staff-only until a real
     * gated owner exists to define one.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->can('documents.review');
    }

    public function create(User $user): bool
    {
        return $user->can('documents.review') || $user->can('companies.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.review');
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents.review') || $user->can('companies.manage');
    }

    public function approve(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'verification_officer']);
    }

    public function reject(User $user): bool
    {
        return $this->approve($user);
    }
}
