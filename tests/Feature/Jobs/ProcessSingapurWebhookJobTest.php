<?php

namespace Tests\Feature\Jobs;

use App\Enums\WebhookEventStatusEnum;
use App\Jobs\ProcessSingapurWebhook;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\NewExpedienteReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the ProcessSingapurWebhook job.
 *
 * Covers notification dispatch: that super_admin users receive a
 * NewExpedienteReceived notification after successful processing, and
 * that users with other roles are not notified.
 *
 * Uses Notification::fake() to intercept notifications without hitting
 * the database notifications table, keeping assertions clean and fast.
 */
class ProcessSingapurWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist — the seeder is not run automatically in tests.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'notario',     'guard_name' => 'web']);
    }

    // -------------------------------------------------------------------------
    // Notification dispatch
    // -------------------------------------------------------------------------

    #[Test]
    public function it_notifies_super_admin_users_after_processing(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $event = WebhookEvent::factory()->create([
            'payload' => $this->validPayload(),
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        ProcessSingapurWebhook::dispatchSync($event);

        Notification::assertSentTo($admin, NewExpedienteReceived::class);
    }

    #[Test]
    public function it_does_not_notify_users_without_super_admin_role(): void
    {
        Notification::fake();

        $notario = User::factory()->create();
        $notario->assignRole('notario');

        $event = WebhookEvent::factory()->create([
            'payload' => $this->validPayload(),
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        ProcessSingapurWebhook::dispatchSync($event);

        Notification::assertNotSentTo($notario, NewExpedienteReceived::class);
    }

    #[Test]
    public function it_notifies_all_super_admin_users_not_just_the_first(): void
    {
        Notification::fake();

        $adminOne = User::factory()->create();
        $adminOne->assignRole('super_admin');

        $adminTwo = User::factory()->create();
        $adminTwo->assignRole('super_admin');

        $event = WebhookEvent::factory()->create([
            'payload' => $this->validPayload(),
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        ProcessSingapurWebhook::dispatchSync($event);

        Notification::assertSentTo($adminOne, NewExpedienteReceived::class);
        Notification::assertSentTo($adminTwo, NewExpedienteReceived::class);
    }

    #[Test]
    public function it_stores_the_notification_in_the_database(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $event = WebhookEvent::factory()->create([
            'payload' => $this->validPayload(),
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        ProcessSingapurWebhook::dispatchSync($event);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
            'type' => NewExpedienteReceived::class,
        ]);
    }

    #[Test]
    public function it_includes_the_client_code_in_the_notification_body(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $event = WebhookEvent::factory()->create([
            'payload' => $this->validPayload(),
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        ProcessSingapurWebhook::dispatchSync($event);

        $notification = $admin->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('000001', $notification->data['body']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal valid webhook payload matching the Singapur relay format.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'event_id' => 'evt-notify-test-001',
            'id' => '7dde1760-57d4-4f4e-b81b-3ae2b93025d0',
            'type' => 'company-registration',
            'registration_number' => '000001',
            'company_folder_name' => '000001_NOVA CONSULTORA EMPRESARIAL',
            'document_group' => 'KYC',
            'created_at' => '2026-06-14T22:35:56.765341+00:00',
            'fields' => [
                'companyName' => 'NOVA CONSULTORÍA EMPRESARIAL',
                'companyType' => 'sa',
                'shareholderCount' => '1',
                'shareholderType1' => 'natural',
                'naturalShareholderName1' => 'Jiaxin Wu',
                'naturalShareholderEmail1' => 'jiaxin@example.com',
                'naturalSharePercentage1' => '100',
                'naturalNationality1' => 'china',
                'naturalOtherNationality1' => '',
                'naturalMarried1' => 'no',
                '_language' => 'zh',
            ],
            'files' => [
                [
                    'field' => 'naturalTaxCertificate1',
                    'original_name' => 'JIAXIN_WU_TAX_ID.pdf',
                    'relay_name' => '000001__naturalTaxCertificate1__JIAXIN_WU_TAX_ID.pdf',
                    'size' => 108548,
                    'content_type' => 'application/pdf',
                    'content' => base64_encode('fake-pdf-content'),
                ],
            ],
        ];
    }
}
