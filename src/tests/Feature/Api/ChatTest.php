<?php

namespace Tests\Feature\Api;

use App\Models\Chat;
use App\Models\Item;
use App\Models\SoldItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use App\Models\Condition;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed conditions required by ItemFactory
        Condition::insert([
            ['id' => 1, 'content' => 'Condition 1', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'content' => 'Condition 2', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'content' => 'Condition 3', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'content' => 'Condition 4', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_index_chat()
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->create(['seller_id' => $seller->id]);
        $soldItem = SoldItem::factory()->create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id
        ]);

        Chat::factory()->count(3)->create([
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id
        ]);

        $response = $this->actingAs($buyer)
            ->getJson("/api/v1/items/{$item->id}/chats");

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page'])
            ->assertJsonCount(3, 'data');
    }

    public function test_store_chat()
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->create(['seller_id' => $seller->id]);
        $soldItem = SoldItem::factory()->create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id
        ]);

        $data = ['message' => 'Hello World'];

        $response = $this->actingAs($buyer)
            ->postJson("/api/v1/items/{$item->id}/chats", $data);

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Hello World']);

        $this->assertDatabaseHas('chats', [
            'sold_item_id' => $soldItem->id,
            'message' => 'Hello World'
        ]);
    }

    public function test_update_chat()
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->create(['seller_id' => $seller->id]);
        $soldItem = SoldItem::factory()->create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id
        ]);
        $chat = Chat::factory()->create([
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id,
            'message' => 'Old Message'
        ]);

        $data = ['message' => 'New Message'];

        $response = $this->actingAs($buyer)
            ->patchJson("/api/v1/chats/{$chat->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'New Message']);

        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'message' => 'New Message'
        ]);
    }

    public function test_destroy_chat()
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->create(['seller_id' => $seller->id]);
        $soldItem = SoldItem::factory()->create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id
        ]);
        $chat = Chat::factory()->create([
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id
        ]);

        $response = $this->actingAs($buyer)
            ->deleteJson("/api/v1/chats/{$chat->id}");

        $response->assertStatus(200);
        $this->assertDeleted($chat);
    }

    public function test_cannot_update_others_chat()
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->create(['seller_id' => $seller->id]);
        $soldItem = SoldItem::factory()->create([
            'item_id' => $item->id,
            'buyer_id' => $buyer->id
        ]);
        $chat = Chat::factory()->create([
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id // Buyer's message
        ]);

        $data = ['message' => 'Hacked'];

        // Seller tries to update Buyer's message
        $response = $this->actingAs($seller)
            ->patchJson("/api/v1/chats/{$chat->id}", $data);

        $response->assertStatus(403);
    }
}
