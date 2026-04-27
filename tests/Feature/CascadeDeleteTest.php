<?php

use App\Models\Complaint;
use App\Models\Recommendation;
use App\Models\User;

test('deleting user cascades complaints and recommendations', function () {
    $user = User::factory()->create();
    $complaint = $user->complaints()->create(['complaint' => 'Test complaint']);
    $user->recommendations()->create([
        'complaint_id' => $complaint->id,
        'recommendation' => 'Test recommendation',
    ]);

    expect(Complaint::count())->toBe(1);
    expect(Recommendation::count())->toBe(1);

    $user->delete();

    expect(Complaint::count())->toBe(0);
    expect(Recommendation::count())->toBe(0);
});
