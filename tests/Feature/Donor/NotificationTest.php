<?php

namespace Tests\Feature\Donor;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->donor()->create();
    }

    /**
     * Insert a database notification for a user.
     */
    private function notify(User $user, string $category = 'reminder', bool $read = false): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\DonorNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'category' => $category,
                'title' => 'Upcoming appointment',
                'desc' => 'Your donation is scheduled for tomorrow.',
                'action_label' => 'View',
                'action_route' => '/donor/appointments',
            ]),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_a_donor_sees_their_notifications(): void
    {
        $this->notify($this->donor);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.category', 'reminder')
            ->assertJsonPath('notifications.0.title', 'Upcoming appointment')
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_a_donor_never_sees_another_donors_notifications(): void
    {
        $other = User::factory()->donor()->create();
        $this->notify($other);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('unread_count', 0);
    }

    public function test_notifications_can_be_filtered_by_category(): void
    {
        $this->notify($this->donor, 'reminder');
        $this->notify($this->donor, 'screening');

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications?category=screening')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.category', 'screening');
    }

    public function test_notifications_can_be_filtered_by_read_state(): void
    {
        $this->notify($this->donor, 'reminder', read: true);
        $this->notify($this->donor, 'reminder', read: false);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications?read=0')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.read', false);
    }

    public function test_an_unknown_category_filter_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications?category=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_a_donor_can_mark_one_notification_as_read(): void
    {
        $id = $this->notify($this->donor);

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/notifications/'.$id)
            ->assertOk()
            ->assertJsonPath('read', true);

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_a_donor_cannot_mark_another_donors_notification_as_read(): void
    {
        $other = User::factory()->donor()->create();
        $id = $this->notify($other);

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/notifications/'.$id)
            ->assertNotFound();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_a_donor_can_mark_everything_as_read(): void
    {
        $this->notify($this->donor);
        $this->notify($this->donor, 'screening');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, DB::table('notifications')->whereNull('read_at')->count());
    }

    public function test_marking_all_as_read_leaves_other_donors_untouched(): void
    {
        $other = User::factory()->donor()->create();
        $otherId = $this->notify($other);
        $this->notify($this->donor);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/notifications/mark-all-read')
            ->assertOk();

        $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    public function test_the_unread_count_endpoint_reports_only_unread(): void
    {
        $this->notify($this->donor, 'reminder', read: true);
        $this->notify($this->donor);
        $this->notify($this->donor, 'system');

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    }

    public function test_notifications_are_paginated(): void
    {
        foreach (range(1, 5) as $index) {
            $this->notify($this->donor);
        }

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('meta.total', 5);
    }

    public function test_notification_endpoints_reject_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/donors/notifications')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/donors/notifications/mark-all-read')->assertForbidden();
    }

    public function test_notification_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/donors/notifications')->assertUnauthorized();
        $this->getJson('/api/donors/notifications/unread-count')->assertUnauthorized();
    }

    /**
     * The screening reminder, as the donor's notifications screen sees it.
     *
     * The command's own behaviour is covered in ScreeningWindowTest. What
     * matters here is that the notification files itself under a category the
     * screen already filters by, and that a donor with no email address still
     * gets told -- they are the ones who cannot be reached any other way.
     */
    public function test_the_screening_reminder_files_itself_under_the_screening_category(): void
    {
        $appointment = \App\Models\DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'appointment_datetime' => now()->addDay()->setTime(9, 0),
        ]);

        $this->donor->notify(new \App\Notifications\ScreeningWindowOpen($appointment));

        $row = DB::table('notifications')
            ->where('notifiable_id', $this->donor->id)
            ->where('type', \App\Notifications\ScreeningWindowOpen::class)
            ->first();

        $this->assertNotNull($row, 'The reminder wrote no in-app copy.');

        $data = json_decode($row->data, true);

        $this->assertSame('screening', $data['category']);
        $this->assertSame('/donor/eligibility', $data['action_route']);
        // Read back by the command to decide whether this donor has already
        // been told, so a re-run cannot send twice.
        $this->assertSame($appointment->id, $data['appointment_id']);
        $this->assertSame('opened', $data['stage']);
    }

    public function test_the_final_reminder_is_distinguishable_from_the_first(): void
    {
        $appointment = \App\Models\DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'appointment_datetime' => now()->setTime(9, 0),
        ]);

        $this->donor->notify(new \App\Notifications\ScreeningWindowOpen($appointment, true));

        $data = json_decode(
            DB::table('notifications')->where('notifiable_id', $this->donor->id)->value('data'),
            true
        );

        $this->assertSame('final', $data['stage']);
        $this->assertSame('warning', $data['tone']);
    }

    public function test_the_reminder_reaches_a_donor_who_has_no_email_address(): void
    {
        $this->donor->forceFill(['email' => null, 'email_verified_at' => null])->save();

        $appointment = \App\Models\DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'appointment_datetime' => now()->addDay()->setTime(9, 0),
        ]);

        $notification = new \App\Notifications\ScreeningWindowOpen($appointment);

        // The mail channel is dropped rather than failing at the mailer, and the
        // in-app copy is the only one this donor can read.
        $this->assertSame(['database'], $notification->via($this->donor->fresh()));
    }
}
