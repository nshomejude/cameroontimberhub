<?php

namespace App\Policies;

use App\Models\CompanyInquiry;
use App\Models\User;

class CompanyInquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inquiries.review');
    }

    public function view(User $user, CompanyInquiry $inquiry): bool
    {
        return $user->can('inquiries.review');
    }
}
