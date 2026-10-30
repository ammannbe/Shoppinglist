<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'test-key', 'services.openai.url' => 'https://openai.test/v1']);
        $this->travelTo('2026-11-02 10:00');
    }

    public function test_read_offers_are_saved_and_shared(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['offers' => [
            ['name' => 'Rindshackfleisch', 'brand' => 'M-Classic', 'price' => 9.95, 'regular_price' => 14.2, 'discount' => '−30 %', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-08', 'shop' => 'Migros'],
            ['name' => 'Avocados', 'brand' => null, 'price' => 2.49, 'regular_price' => null, 'discount' => null, 'valid_from' => 'Montag', 'valid_until' => '2026-02-30', 'shop' => 'Lidl'],
        ]]))]);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $offers = $this->actingAs($user)->postJson('/planner/offers', ['text' => 'Aktionen …'])->assertOk()->json('offers');

        $this->assertSame(['M', '2026-11-02', '2026-11-08', true, true], [$offers[0]['store'], $offers[0]['valid_from'], $offers[0]['valid_until'], $offers[0]['shared'], $offers[0]['own']]);
        // Invalid dates from the AI are dropped.
        $this->assertSame([null, null], [$offers[1]['valid_from'], $offers[1]['valid_until']]);
        Http::assertSent(fn (Request $request) => str_contains($request['instructions'], 'the current year is 2026'));

        // Other users see shared offers, but cannot change them.
        $this->actingAs($other)->getJson('/offers')->assertJsonCount(2, 'offers')->assertJsonPath('offers.0.own', false);
        $this->actingAs($other)->patchJson('/offers/'.$offers[0]['id'], ['shared' => false])->assertForbidden();
        $this->actingAs($other)->deleteJson('/offers/'.$offers[0]['id'])->assertForbidden();

        // Reading the same offers again does not add them twice.
        $this->actingAs($user)->postJson('/planner/offers', ['text' => 'Aktionen …', 'share' => false])->assertOk();
        $this->assertSame(2, Offer::count());
    }

    public function test_private_offers_are_only_visible_to_their_owner(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/offers', ['name' => 'Kaffee', 'store' => 'Volg', 'shared' => false, 'discount' => '2 für 1'])
            ->assertCreated()->json('offer.id');

        $this->actingAs(User::factory()->create())->getJson('/offers')->assertJsonCount(0, 'offers');

        $this->actingAs($user)->patchJson("/offers/{$id}", ['shared' => true, 'valid_until' => '2026-11-05'])
            ->assertOk()->assertJsonPath('offer.shared', true)->assertJsonPath('offer.valid_until', '2026-11-05');
        $this->actingAs($user)->patchJson("/offers/{$id}", ['valid_from' => '2026-11-06'])->assertUnprocessable();
        $this->actingAs($user)->deleteJson("/offers/{$id}")->assertNoContent();
        $this->assertSame(0, Offer::count());
    }

    public function test_own_offers_of_a_shop_can_be_deleted_at_once(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([[$user, 'A'], [$user, 'A'], [$user, 'M'], [$user, null], [$other, 'A']] as [$owner, $store]) {
            $this->actingAs($owner)->postJson('/offers', ['name' => 'Offer '.uniqid(), 'store' => $store])->assertCreated();
        }

        $this->actingAs($user)->deleteJson('/offers', ['store' => 'A'])->assertOk()->assertJsonPath('deleted', 2);
        $this->actingAs($user)->deleteJson('/offers', ['store' => null])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertEqualsCanonicalizing(['M', 'A'], Offer::pluck('store')->all());
    }

    public function test_expired_offers_are_hidden_and_pruned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/offers', ['name' => 'Gültig', 'valid_until' => '2026-11-02'])->assertCreated();
        $this->actingAs($user)->postJson('/offers', ['name' => 'Abgelaufen', 'valid_until' => '2026-11-01'])->assertCreated();
        $this->actingAs($user)->postJson('/offers', ['name' => 'Ohne Datum'])->assertCreated();

        $this->actingAs($user)->getJson('/offers')->assertJsonCount(2, 'offers');

        $this->travel(Offer::UNDATED_DAYS + 1)->days();
        $this->artisan('offers:prune')->assertSuccessful();
        $this->assertSame([], Offer::pluck('name')->all());
    }

    public function test_offers_of_other_regions_are_hidden(): void
    {
        $user = User::factory()->create(['preferences' => ['regions' => ['M' => 'migros-aare']]]);
        $other = User::factory()->create();
        foreach ([['Aare', 'M', 'migros-aare'], ['Zürich', 'M', 'migros-zuerich'], ['National', 'M', null], ['Coop', 'C', null]] as [$name, $store, $region]) {
            $this->actingAs($other)->postJson('/offers', compact('name', 'store', 'region'))->assertCreated();
        }
        $this->actingAs($user)->postJson('/offers', ['name' => 'Eigene', 'store' => 'M', 'region' => 'migros-zuerich'])->assertCreated();

        $this->assertEqualsCanonicalizing(['Aare', 'National', 'Coop', 'Eigene'], array_column($this->actingAs($user)->getJson('/offers')->json('offers'), 'name'));
        // Without a chosen region all regions are shown.
        $this->assertCount(5, $this->actingAs($other)->getJson('/offers')->json('offers'));
    }

    public function test_regions_belong_to_their_shop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/offers', ['name' => 'Käse', 'store' => 'M', 'region' => 'Bern'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/offers', ['name' => 'Käse', 'store' => 'A', 'region' => 'migros-aare'])
            ->assertCreated()->assertJsonPath('offer.region', null);
        $id = $this->actingAs($user)->postJson('/offers', ['name' => 'Brot', 'store' => 'M', 'region' => 'migros-aare'])
            ->assertCreated()->assertJsonPath('offer.region', 'migros-aare')->json('offer.id');

        $this->actingAs($user)->patchJson("/offers/{$id}", ['store' => 'C'])->assertOk()->assertJsonPath('offer.region', null);
    }

    public function test_read_offers_get_the_region_of_the_material_or_the_user(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['offers' => [
            ['name' => 'Zopf', 'brand' => null, 'price' => 4.5, 'regular_price' => null, 'discount' => null, 'valid_from' => null, 'valid_until' => '2026-11-08', 'shop' => 'Migros', 'region' => 'migros-zuerich'],
            ['name' => 'Butter', 'brand' => null, 'price' => 3.2, 'regular_price' => null, 'discount' => null, 'valid_from' => null, 'valid_until' => '2026-11-08', 'shop' => 'Migros', 'region' => null],
            ['name' => 'Avocados', 'brand' => null, 'price' => 2.49, 'regular_price' => null, 'discount' => null, 'valid_from' => null, 'valid_until' => '2026-11-08', 'shop' => 'Lidl', 'region' => 'migros-aare'],
        ]]))]);
        $user = User::factory()->create(['preferences' => ['regions' => ['M' => 'migros-aare']]]);

        $offers = $this->actingAs($user)->postJson('/planner/offers', ['text' => 'Aktionen …'])->assertOk()->json('offers');

        $this->assertSame(['migros-zuerich', 'migros-aare', null], array_column($offers, 'region'));
        Http::assertSent(fn (Request $request) => str_contains($request['instructions'], 'Migros Aare (AG, BE, SO)'));
    }

    public function test_offers_need_a_verified_account(): void
    {
        $this->getJson('/offers')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson('/offers')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ai(array $data): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]];
    }
}
