<?php

namespace Tests\Feature;

use App\Announcements\AccountManagerAnnouncements;
use App\Announcements\AnnouncementDispatcher;
use App\Http\Controllers\AnnouncementUnsubscribeController;
use App\Jobs\SendAnnouncementBatch;
use App\Jobs\SyncAnnouncements;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRead;
use App\Models\User;
use Closure;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * ANNOUNCEMENTS_DRIVER=account-manager: announcements mirrored from the AccountManager (contract: AccountManager
 * docs/ANNOUNCEMENTS-API.md), shown in the app and sent by push and email.
 */
class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'f00dbabe00000000000000000000000000000000000000000000000000000000';

    private const UUID = '019a8c1e-4b2d-7f3a-9e10-5c6d7e8f9a0b';

    private const OTHER = '019a8c1e-4b2d-7f3a-9e10-5c6d7e8f9a0c';

    /**
     * Answers of the fake AccountManager: "GET announcements" => [status, body] or a closure.
     *
     * @var array<string, mixed>
     */
    private array $accountManager = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    /** Pushes of the fake WebPushChannel: [user id, title, body, url]. */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:30');
        config([
            'announcements.driver' => 'account-manager',
            'households.account_manager' => ['url' => 'https://account-manager.test', 'key' => 'shoppinglist', 'secret' => self::SECRET, 'report_shares' => false],
        ]);

        Http::fake(function (HttpRequest $request) {
            $this->requests[] = $request;
            $route = $request->method().' '.Str::before(Str::after($request->url(), 'https://account-manager.test/api/'), '?');
            $answer = $this->accountManager[$route] ?? [404, ['message' => 'Not found.']];
            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return Http::response($answer[1], $answer[0]);
        });

        $test = $this;
        $this->app->instance(WebPushChannel::class, new class($test) extends WebPushChannel
        {
            public function __construct(private AnnouncementsTest $test) {}

            public function send(mixed $notifiable, Notification $notification): array
            {
                return $this->test->fakePush($notifiable, $notification);
            }
        });
        $this->accountManager['POST announcements/'.self::UUID.'/deliveries'] = [200, ['data' => []]];
    }

    /**
     * @return list<MessageSentReport>
     */
    public function fakePush(User $user, Notification $notification): array
    {
        $message = $notification->toWebPush($user, $notification)->toArray();
        $this->pushes[] = [$user->id, $message['title'], $message['body'], $message['data']['url']];

        return $user->pushSubscriptions->map(fn ($subscription) => new MessageSentReport(new Psr7Request('POST', $subscription->endpoint)))->all();
    }

    private function announcement(array $overrides = []): array
    {
        return [
            'uuid' => self::UUID,
            'type' => 'feature',
            'title' => ['de' => 'Neu: Wochenplan', 'en' => 'New: weekly plan'],
            'body' => ['de' => 'Plane die **Woche**', 'en' => 'Plan your **week**'],
            'body_html' => ['de' => '<p>Plane die <strong>Woche</strong></p>', 'en' => '<p>Plan your <strong>week</strong></p>'],
            'excerpt' => ['de' => 'Plane die Woche', 'en' => 'Plan your week'],
            'channels' => ['in_app', 'banner'],
            'link' => 'https://help.example.com/weekly-plan',
            'image_url' => null,
            'publish_at' => '2026-11-14T09:00:00Z',
            'expires_at' => null,
            'updated_at' => '2026-11-14T09:00:00Z',
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  list<string>  $deleted
     */
    private function answer(array $data, array $deleted = [], string $serverTime = '2026-11-14T10:30:00Z'): void
    {
        $this->accountManager['GET announcements'] = [200, ['data' => $data, 'deleted' => $deleted, 'server_time' => $serverTime]];
    }

    private function sync(bool $full = false): ?array
    {
        return app(AccountManagerAnnouncements::class)->sync($full);
    }

    private function webhook(array $options = []): TestResponse
    {
        $body = json_encode([
            'event_id' => $options['event_id'] ?? (string) Str::uuid(),
            'type' => 'announcements.changed',
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => ['announcement' => ['uuid' => self::UUID, 'change' => 'published']],
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($options['timestamp'] ?? now()->getTimestamp());
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $options['secret'] ?? self::SECRET);

        return $this->call('POST', '/api/account-manager/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ACCOUNT_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_ACCOUNT_MANAGER_SIGNATURE' => $options['signature'] ?? $signature,
        ], content: $body);
    }

    /** A user registered before the announcements of the tests. */
    private function user(array $attributes = []): User
    {
        return User::factory()->create(['created_at' => '2026-11-01 08:00', ...$attributes]);
    }

    private function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): void
    {
        $user->updatePushSubscription($endpoint, 'p256dh-key', 'auth-token', 'aes128gcm');
    }

    /**
     * @return list<array{channel: string, sent: int, failed: int, batch: string}>
     */
    private function deliveryReports(): array
    {
        return array_values(array_map(fn (HttpRequest $request) => $request->data(), array_filter(
            $this->requests,
            fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/deliveries'),
        )));
    }

    /**
     * @return list<SentMessage>
     */
    private function sentMails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->all();
    }

    // ---------------------------------------------------------------- sync

    public function test_the_first_sync_fetches_all_and_the_next_one_uses_the_server_time(): void
    {
        $this->answer([$this->announcement()]);

        $this->assertSame(['upserted' => 1, 'withdrawn' => 0], $this->sync());

        $this->assertArrayNotHasKey('since', $this->requests[0]->data());
        $this->assertSame('2026-11-14T10:30:00Z', Cache::get(AccountManagerAnnouncements::SERVER_TIME_KEY));
        $announcement = Announcement::sole();
        $this->assertSame(self::UUID, $announcement->account_manager_uuid);
        $this->assertSame(['in_app', 'banner'], $announcement->channels);
        $this->assertSame('2026-11-14 09:00:00', $announcement->publish_at->toDateTimeString());
        $this->assertSame('<p>Plane die <strong>Woche</strong></p>', $announcement->body_html['de']);

        $this->answer([], serverTime: '2026-11-14T10:45:00Z');
        $this->sync();

        $this->assertStringContainsString('since='.rawurlencode('2026-11-14T10:30:00Z'), $this->requests[1]->url());
        $this->assertSame('2026-11-14T10:45:00Z', Cache::get(AccountManagerAnnouncements::SERVER_TIME_KEY));
        $this->assertSame(1, Announcement::count(), 'An answer with since is not complete.');
    }

    public function test_repeated_announcements_are_stored_idempotently_and_changes_are_applied(): void
    {
        $this->answer([$this->announcement()]);
        $this->sync();

        $this->answer([$this->announcement()]);
        $this->assertSame(['upserted' => 0, 'withdrawn' => 0], $this->sync());

        $this->answer([$this->announcement(['title' => ['de' => 'Neu: Wochenplaner', 'en' => null], 'type' => 'surprise', 'channels' => ['in_app', 'fax'], 'updated_at' => '2026-11-14T10:40:00Z'])]);
        $this->assertSame(['upserted' => 1, 'withdrawn' => 0], $this->sync());

        $announcement = Announcement::sole();
        $this->assertSame(['de' => 'Neu: Wochenplaner', 'en' => null], $announcement->title);
        $this->assertSame('info', $announcement->type, 'Unknown types count as info.');
        $this->assertSame(['in_app'], $announcement->channels, 'Unknown channels are left out.');
    }

    public function test_deleted_announcements_are_hidden_but_kept_with_their_reads(): void
    {
        $user = $this->user();
        $this->answer([$this->announcement(), $this->announcement(['uuid' => self::OTHER])]);
        $this->sync();
        $this->actingAs($user)->postJson('/announcements/read', ['read' => [self::UUID]])->assertNoContent();

        $this->answer([], [self::UUID]);
        $this->assertSame(['upserted' => 0, 'withdrawn' => 1], $this->sync());

        $this->assertSame([self::OTHER], Announcement::active()->pluck('account_manager_uuid')->all());
        $this->assertSame(2, Announcement::count(), 'Hidden, not deleted.');
        $this->assertSame(1, AnnouncementRead::count());
        $this->assertSame([self::OTHER], array_column($this->actingAs($user)->getJson('/announcements')->json('data'), 'id'));

        // Coming back (same updated_at): shown again, still read.
        $this->answer([$this->announcement()]);
        $this->assertSame(['upserted' => 1, 'withdrawn' => 0], $this->sync());
        $this->assertNull(Announcement::where('account_manager_uuid', self::UUID)->value('withdrawn_at'));
        $items = collect($this->actingAs($user)->getJson('/announcements')->json('data'))->keyBy('id');
        $this->assertNotNull($items[self::UUID]['read_at']);
    }

    public function test_a_withdrawn_announcement_coming_back_is_not_sent_again(): void
    {
        $user = $this->user(['announcement_emails' => 'all']);
        $this->subscribe($user);
        $this->answer([$this->announcement(['channels' => ['in_app', 'push', 'email']])]);
        $this->sync();
        $this->assertCount(1, $this->pushes);
        $this->assertCount(1, $this->sentMails());

        $this->answer([], [self::UUID]);
        $this->sync();
        $this->answer([$this->announcement(['channels' => ['in_app', 'push', 'email'], 'updated_at' => '2026-11-14T10:40:00Z'])]);
        $this->sync();

        $this->assertTrue(Announcement::sole()->isActive());
        $this->assertCount(1, $this->pushes);
        $this->assertCount(1, $this->sentMails());
    }

    public function test_the_full_sync_hides_copies_missing_in_the_complete_list(): void
    {
        $this->answer([$this->announcement(), $this->announcement(['uuid' => self::OTHER])]);
        $this->sync();
        Cache::forever(AccountManagerAnnouncements::SERVER_TIME_KEY, '2026-11-14T10:30:00Z');

        $this->answer([$this->announcement()], serverTime: '2026-11-15T04:50:00Z');
        $this->artisan('account-manager:sync-announcements', ['--full' => true])->assertSuccessful();

        $this->assertArrayNotHasKey('since', end($this->requests)->data(), 'The full sync does not send since.');
        $this->assertSame([self::UUID], Announcement::active()->pluck('account_manager_uuid')->all());
        $this->assertNotNull(Announcement::where('account_manager_uuid', self::OTHER)->value('withdrawn_at'));
        $this->assertSame('2026-11-15T04:50:00Z', Cache::get(AccountManagerAnnouncements::SERVER_TIME_KEY));
    }

    public function test_the_last_state_stays_while_the_account_manager_is_down(): void
    {
        $this->answer([$this->announcement()]);
        $this->sync();
        $this->accountManager['GET announcements'] = [503, []];

        $this->artisan('account-manager:sync-announcements')->assertFailed();

        $this->assertSame(1, Announcement::count());
        $this->assertSame('2026-11-14T10:30:00Z', Cache::get(AccountManagerAnnouncements::SERVER_TIME_KEY));
    }

    public function test_invalid_announcements_are_skipped(): void
    {
        $this->answer([$this->announcement(['title' => ['de' => null, 'en' => null]]), $this->announcement(['uuid' => self::OTHER])]);

        $this->sync();

        $this->assertSame([self::OTHER], Announcement::pluck('account_manager_uuid')->all());
    }

    public function test_the_driver_none_does_nothing(): void
    {
        config(['announcements.driver' => 'none']);

        $this->artisan('account-manager:sync-announcements')->assertSuccessful();

        $this->assertSame([], $this->requests);
        $this->actingAs($this->user())->getJson('/announcements')->assertExactJson(['data' => []]);
        $this->getJson('/me')->assertJsonPath('announcements', null);
    }

    // ---------------------------------------------------------------- webhook

    public function test_the_webhook_queues_a_sync_once_per_event(): void
    {
        Queue::fake();
        $eventId = (string) Str::uuid();

        $this->webhook(['event_id' => $eventId])->assertNoContent();
        $this->webhook(['event_id' => $eventId])->assertNoContent();

        Queue::assertPushed(SyncAnnouncements::class, 1);
    }

    public function test_the_webhook_triggers_a_sync_with_the_last_server_time(): void
    {
        Cache::forever(AccountManagerAnnouncements::SERVER_TIME_KEY, '2026-11-14T10:00:00Z');
        $this->answer([$this->announcement()]);

        $this->webhook()->assertNoContent();

        $this->assertStringContainsString('since='.rawurlencode('2026-11-14T10:00:00Z'), $this->requests[0]->url());
        $this->assertSame(1, Announcement::count());
    }

    public function test_invalid_and_expired_signatures_are_rejected(): void
    {
        Queue::fake();

        $this->webhook(['secret' => str_repeat('0', 64)])->assertUnauthorized();
        $this->webhook(['timestamp' => now()->subMinutes(6)->getTimestamp()])->assertUnauthorized();
        $this->webhook(['signature' => ''])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_the_webhook_is_closed_without_anything_from_the_account_manager(): void
    {
        config(['announcements.driver' => 'none']);

        $this->webhook()->assertNotFound();
    }

    // ---------------------------------------------------------------- visibility, reads

    public function test_users_see_active_announcements_in_their_language(): void
    {
        $this->answer([
            $this->announcement(),
            $this->announcement(['uuid' => self::OTHER, 'title' => ['de' => 'Nur Deutsch', 'en' => null], 'body_html' => ['de' => '<p>Text</p>', 'en' => null], 'publish_at' => '2026-11-14T08:00:00Z']),
            $this->announcement(['uuid' => (string) Str::uuid(), 'expires_at' => '2026-11-14T10:00:00Z']),
            $this->announcement(['uuid' => (string) Str::uuid(), 'channels' => ['push', 'email']]),
        ]);
        $this->sync();
        $user = $this->user(['locale' => 'en']);

        $response = $this->actingAs($user)->getJson('/announcements')->assertOk();

        $this->assertSame([self::UUID, self::OTHER], array_column($response->json('data'), 'id'), 'Expired and push/email-only ones are not listed.');
        $response->assertJsonPath('data.0.title', 'New: weekly plan')
            ->assertJsonPath('data.0.body_html', '<p>Plan your <strong>week</strong></p>')
            ->assertJsonPath('data.0.excerpt', 'Plan your week')
            ->assertJsonPath('data.0.in_app', true)
            ->assertJsonPath('data.0.banner', true)
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.0.published_at', Carbon::parse('2026-11-14T09:00:00Z')->getTimestampMs())
            ->assertJsonPath('data.1.title', 'Nur Deutsch')
            ->assertJsonPath('data.1.body_html', '<p>Text</p>');

        $user->forceFill(['locale' => 'de_CH'])->save();
        $this->getJson('/announcements')->assertJsonPath('data.0.title', 'Neu: Wochenplan');
    }

    public function test_new_users_do_not_see_older_announcements_except_maintenance(): void
    {
        $this->answer([
            $this->announcement(),
            $this->announcement(['uuid' => self::OTHER, 'type' => 'maintenance', 'publish_at' => '2026-11-14T08:00:00Z']),
        ]);
        $this->sync();
        $newcomer = User::factory()->create(['created_at' => '2026-11-14 10:00']);

        $response = $this->actingAs($newcomer)->getJson('/announcements')->assertOk();

        $this->assertSame([self::OTHER], array_column($response->json('data'), 'id'));
    }

    public function test_announcements_are_marked_read_and_dismissed_per_user(): void
    {
        $this->answer([$this->announcement(), $this->announcement(['uuid' => self::OTHER])]);
        $this->sync();
        $user = $this->user();
        $other = $this->user();

        $this->actingAs($user)->postJson('/announcements/read', ['read' => [self::UUID, strtoupper(self::OTHER)], 'dismissed' => [self::UUID]])->assertNoContent();
        Carbon::setTestNow('2026-11-14 11:00');
        $this->postJson('/announcements/read', ['read' => [self::UUID], 'dismissed' => ['019a0000-0000-7000-8000-000000000000']])->assertNoContent();

        $data = collect($this->getJson('/announcements')->json('data'))->keyBy('id');
        $this->assertSame(Carbon::parse('2026-11-14 10:30')->getTimestampMs(), $data[self::UUID]['read_at'], 'The first read counts.');
        $this->assertNotNull($data[self::UUID]['dismissed_at']);
        $this->assertNotNull($data[self::OTHER]['read_at']);
        $this->assertNull($data[self::OTHER]['dismissed_at']);

        $this->actingAs($other)->getJson('/announcements')->assertJsonPath('data.0.read_at', null);
        $this->assertSame(2, AnnouncementRead::count());
        $this->postJson('/announcements/read', ['read' => ['not-a-uuid']])->assertUnprocessable();
    }

    public function test_me_tells_the_app_about_announcements(): void
    {
        config(['webpush.vapid.public_key' => 'BPublicKey']);
        $user = $this->user();
        $this->subscribe($user);

        $this->actingAs($user)->getJson('/me')
            ->assertJsonPath('announcements.push_key', 'BPublicKey')
            ->assertJsonPath('announcements.push_devices', 1)
            ->assertJsonPath('announcements.emails', 'maintenance');
    }

    // ---------------------------------------------------------------- push

    public function test_push_goes_once_to_users_with_a_subscribed_device(): void
    {
        $subscribed = $this->user(['locale' => 'en']);
        $this->subscribe($subscribed);
        $this->subscribe($subscribed, 'https://updates.push.services.mozilla.com/wpush/v2/xyz');
        $this->user();
        $newcomer = User::factory()->create(['created_at' => '2026-11-14 10:00']);
        $this->subscribe($newcomer, 'https://fcm.googleapis.com/fcm/send/new');

        $this->answer([$this->announcement(['channels' => ['in_app', 'push']])]);
        $this->sync();
        // A later sync (also a changed announcement) does not send again.
        $this->answer([$this->announcement(['channels' => ['in_app', 'push'], 'updated_at' => '2026-11-14T10:40:00Z'])]);
        $this->sync();
        app(AnnouncementDispatcher::class)->dispatchDue();

        $this->assertSame([[$subscribed->id, 'New: weekly plan', 'Plan your week', '/#/news/'.self::UUID]], $this->pushes);
        $this->assertNotNull(Announcement::sole()->push_dispatched_at);
        $this->assertSame([['channel' => 'push', 'sent' => 1, 'failed' => 0, 'batch' => 'push-1']], $this->deliveryReports());
    }

    public function test_a_package_that_runs_again_sends_nobody_twice(): void
    {
        $users = collect(range(1, 3))->map(fn () => tap($this->user(), fn (User $user) => $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/'.Str::random(8))));
        $this->answer([$this->announcement(['channels' => ['push']])]);
        Queue::fake();
        $this->sync();
        $announcement = Announcement::sole();

        // Overlapping runs: the channel is claimed once.
        app(AnnouncementDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendAnnouncementBatch::class, 1);

        $job = new SendAnnouncementBatch($announcement->id, 'push', $users->pluck('id')->all(), 'push-1');
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertCount(3, $this->pushes);
        $this->assertSame(3, AnnouncementDelivery::where('status', 'sent')->count());
        $this->assertSame(
            [['channel' => 'push', 'sent' => 3, 'failed' => 0, 'batch' => 'push-1'], ['channel' => 'push', 'sent' => 3, 'failed' => 0, 'batch' => 'push-1']],
            $this->deliveryReports(),
            'A retry reports the whole package again with the same batch (replaces its numbers).',
        );
    }

    public function test_packages_are_small_and_have_their_own_batch_ids(): void
    {
        config(['announcements.batch_size' => 2]);
        collect(range(1, 5))->each(fn () => $this->subscribe($this->user(), 'https://fcm.googleapis.com/fcm/send/'.Str::random(8)));
        $this->answer([$this->announcement(['channels' => ['push']])]);

        $this->sync();

        $this->assertCount(5, $this->pushes);
        $this->assertSame(['push-1', 'push-2', 'push-3'], array_column($this->deliveryReports(), 'batch'));
        $this->assertSame([2, 2, 1], array_column($this->deliveryReports(), 'sent'));
    }

    public function test_old_and_expired_announcements_are_not_sent(): void
    {
        $this->subscribe($this->user(['created_at' => '2026-10-01 08:00']));
        $this->answer([$this->announcement(['channels' => ['push'], 'publish_at' => '2026-11-01T09:00:00Z'])]);

        $this->sync();

        $this->assertSame([], $this->pushes, 'First seen more than 7 days after the publication.');
        $this->assertNotNull(Announcement::sole()->push_dispatched_at);

        $announcement = Announcement::sole();
        $announcement->forceFill(['publish_at' => now()->subHour(), 'expires_at' => now()->subMinute()])->save();
        app()->call([new SendAnnouncementBatch($announcement->id, 'push', User::pluck('id')->all(), 'push-1'), 'handle']);
        $this->assertSame([], $this->pushes);
    }

    public function test_only_announcements_of_the_last_seven_days_are_sent(): void
    {
        $this->assertSame(7, config('announcements.send_max_age_days'));
        $this->subscribe($this->user(['created_at' => '2026-10-01 08:00']));
        $this->answer([
            $this->announcement(['channels' => ['push'], 'publish_at' => '2026-11-07T11:00:00Z']),
            $this->announcement(['uuid' => self::OTHER, 'channels' => ['push'], 'publish_at' => '2026-11-07T09:00:00Z']),
        ]);

        $this->sync();

        $this->assertCount(1, $this->pushes);
    }

    public function test_devices_are_subscribed_only_at_known_push_services(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->postJson('/push-subscriptions', ['endpoint' => 'https://evil.example.com/push', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertUnprocessable();
        $this->postJson('/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertNoContent();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->deleteJson('/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    // ---------------------------------------------------------------- email

    public function test_emails_go_only_to_verified_users_who_opted_in(): void
    {
        $all = $this->user(['email' => 'all@example.com']);
        $all->forceFill(['announcement_emails' => 'all'])->save();
        $this->user(['email' => 'default@example.com']);
        $this->user(['email' => 'none@example.com'])->forceFill(['announcement_emails' => 'none'])->save();
        $this->user(['email' => 'unverified@example.com', 'email_verified_at' => null])->forceFill(['announcement_emails' => 'all'])->save();
        $this->accountManager['POST announcements/'.self::OTHER.'/deliveries'] = [200, ['data' => []]];

        $this->answer([
            $this->announcement(['channels' => ['email']]),
            $this->announcement(['uuid' => self::OTHER, 'type' => 'maintenance', 'channels' => ['email'], 'title' => ['de' => 'Wartung am Sonntag', 'en' => null]]),
        ]);
        $this->sync();
        $this->sync();

        $recipients = collect($this->sentMails())->map(fn (SentMessage $message) => [
            $message->getOriginalMessage()->getTo()[0]->getAddress(),
            $message->getOriginalMessage()->getSubject(),
        ])->sortBy(fn ($entry) => implode(' ', $entry))->values()->all();
        $this->assertSame([
            ['all@example.com', 'Neu: Wochenplan'],
            ['all@example.com', 'Wartung am Sonntag'],
            ['default@example.com', 'Wartung am Sonntag'],
        ], $recipients, 'Maintenance notices go to the default setting, the others only with "all".');
        $this->assertEqualsCanonicalizing([
            ['channel' => 'email', 'sent' => 1, 'failed' => 0, 'batch' => 'email-1'],
            ['channel' => 'email', 'sent' => 2, 'failed' => 0, 'batch' => 'email-1'],
        ], $this->deliveryReports());
    }

    public function test_emails_have_a_signed_unsubscribe_link_and_headers(): void
    {
        $user = $this->user(['locale' => 'en']);
        $user->forceFill(['announcement_emails' => 'all'])->save();
        $this->answer([$this->announcement(['channels' => ['email']])]);

        $this->sync();

        $message = $this->sentMails()[0]->getOriginalMessage();
        $url = AnnouncementUnsubscribeController::url($user);
        $this->assertSame('<'.$url.'>', $message->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString('Plan your <strong', $message->getHtmlBody());
        $this->assertStringContainsString(e($url), $message->getHtmlBody());
        $this->assertStringContainsString('https://help.example.com/weekly-plan', $message->getHtmlBody());
    }

    public function test_the_unsubscribe_link_turns_off_all_announcement_emails(): void
    {
        $user = $this->user();
        $user->forceFill(['announcement_emails' => 'all'])->save();
        $url = AnnouncementUnsubscribeController::url($user);

        $this->get($url)->assertOk()->assertSee($user->email);
        $this->assertSame('all', $user->fresh()->announcement_emails, 'Opening the link (e.g. a link scanner) does not unsubscribe.');

        $this->post($url)->assertOk();
        $this->assertSame('none', $user->fresh()->announcement_emails);

        $this->post($url.'x')->assertForbidden();
        $this->get(route('announcements.unsubscribe', ['user' => $user->id]))->assertForbidden();
    }

    public function test_mail_clients_unsubscribe_with_one_click(): void
    {
        $user = $this->user();
        $user->forceFill(['announcement_emails' => 'all'])->save();

        $this->post(AnnouncementUnsubscribeController::url($user), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();

        $this->assertSame('none', $user->fresh()->announcement_emails);
    }

    public function test_users_choose_which_news_they_get_by_email(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->putJson('/announcements/emails', ['announcement_emails' => 'all'])->assertNoContent();
        $this->assertSame('all', $user->fresh()->announcement_emails);
        $this->putJson('/announcements/emails', ['announcement_emails' => 'sometimes'])->assertUnprocessable();
    }

    public function test_delivery_report_failures_are_ignored(): void
    {
        $this->subscribe($this->user());
        $this->accountManager['POST announcements/'.self::UUID.'/deliveries'] = [500, []];
        $this->answer([$this->announcement(['channels' => ['push']])]);

        $this->sync();

        $this->assertCount(1, $this->pushes);
        $this->assertSame(1, AnnouncementDelivery::where('status', 'sent')->count());
    }

    public function test_deleting_the_account_removes_its_push_subscriptions(): void
    {
        $user = $this->user();
        $this->subscribe($user);

        $this->actingAs($user)->deleteJson('/account', ['password' => 'password'])->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }
}
