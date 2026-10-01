<?php

use App\Models\Company;
use App\Models\Dispute;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\CompanyVerifiedNotification;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeReplyNotification;

it('sends company verification over mail, database and push with a verification deep link', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['legal_name' => 'Bois Sanaga']);
    $notification = new CompanyVerifiedNotification($company);

    expect($notification->via($user))->toBe(['mail', 'database', ExpoPushChannel::class])
        ->and($notification->toArray($user))->toMatchArray([
            'type' => 'company_verified',
            'company_id' => $company->id,
            'screen' => 'verification',
        ])
        ->and($notification->toArray($user)['title'])->toContain('Bois Sanaga');
});

it('respects notification preferences for company verification but always mails', function () {
    $user = User::factory()->create();
    NotificationPreference::forUser($user)->update([
        'channels' => ['push' => false, 'email' => true],
        'types' => ['company_verified' => false],
    ]);

    expect((new CompanyVerifiedNotification(Company::factory()->create()))->via($user->fresh()))->toBe(['mail']);
});

it('stores the company verification in the notification centre', function () {
    $user = User::factory()->create();
    $user->notifyNow(new CompanyVerifiedNotification(Company::factory()->create()), ['database']);

    expect($user->notifications()->first()->data['screen'])->toBe('verification');
});

it('carries dispute_id on dispute notifications', function () {
    $dispute = (new Dispute)->forceFill(['id' => 4242]);
    $dispute->setRelation('order', null);
    $user = User::factory()->make();

    expect((new DisputeReplyNotification($dispute, 'hello'))->toArray($user))->toMatchArray(['dispute_id' => 4242, 'screen' => 'dispute'])
        ->and((new DisputeOpenedNotification($dispute))->toArray($user))->toMatchArray(['dispute_id' => 4242, 'screen' => 'dispute']);
});
