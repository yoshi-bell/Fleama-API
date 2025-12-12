<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use App\Models\SoldItem;
use App\Models\Chat;
use App\Models\Rating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\TransactionCompleted;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        // Conditionを作成
        \App\Models\Condition::create(['content' => '新品、未使用']);
        \App\Models\Condition::create(['content' => '未使用に近い']);
        \App\Models\Condition::create(['content' => '目立った傷や汚れなし']);
        \App\Models\Condition::create(['content' => 'やや傷や汚れあり']);
    }

    public function test_user_can_access_chat_page()
    {
        $this->withoutExceptionHandling();
        $seller = User::factory()->create();
        $seller->markEmailAsVerified();
        $buyer = User::factory()->create();
        $buyer->markEmailAsVerified();
        $item = Item::factory()->create(['seller_id' => $seller->id, 'condition_id' => 1]);
        SoldItem::create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id,
            'postcode' => '123-4567',
            'address' => 'Test Address',
            'building' => 'Test Building',
        ]);

        $this->actingAs($buyer)
            ->get(route('chat.index', $item->id))
            ->assertStatus(200)
            ->assertSee($item->name);
    }

    public function test_user_can_send_message()
    {
        $this->withoutExceptionHandling();
        Storage::fake('public');
        $seller = User::factory()->create();
        $seller->markEmailAsVerified();
        $buyer = User::factory()->create();
        $buyer->markEmailAsVerified();
        $item = Item::factory()->create(['seller_id' => $seller->id, 'condition_id' => 1]);
        SoldItem::create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id,
            'postcode' => '123-4567',
            'address' => 'Test Address',
            'building' => 'Test Building',
        ]);

        $response = $this->actingAs($buyer)
            ->postJson(route('api.chat.store', $item->id), [
                'message' => 'Hello Seller!',
                'image' => UploadedFile::fake()->image('test.jpg'),
            ]);

        $response->assertStatus(201);

        $soldItem = SoldItem::where('item_id', $item->id)->first();
        $this->assertDatabaseHas('chats', [
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id,
            'message' => 'Hello Seller!',
        ]);
    }

    public function test_user_can_rate_and_complete_transaction()
    {
        $this->withoutExceptionHandling();
        Mail::fake();
        $seller = User::factory()->create();
        $seller->markEmailAsVerified();
        $buyer = User::factory()->create();
        $buyer->markEmailAsVerified();
        $item = Item::factory()->create(['seller_id' => $seller->id, 'condition_id' => 1]);
        SoldItem::create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id,
            'postcode' => '123-4567',
            'address' => 'Test Address',
            'building' => 'Test Building',
        ]);

        $response = $this->actingAs($buyer)
            ->post(route('rating.store', $item->id), [
                'rating' => 5,
            ]);

        $response->assertRedirect(route('index'));

        $soldItem = SoldItem::where('item_id', $item->id)->first();
        $this->assertDatabaseHas('ratings', [
            'sold_item_id' => $soldItem->id,
            'rater_id' => $buyer->id,
            'rated_user_id' => $seller->id,
            'rating' => 5,
        ]);

        Mail::assertSent(TransactionCompleted::class);
    }
}
