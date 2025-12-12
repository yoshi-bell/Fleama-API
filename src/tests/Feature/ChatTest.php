<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Item;
use App\Models\SoldItem;
use App\Models\Chat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ChatTest extends TestCase
{
    use RefreshDatabase; // 各テスト後にデータベースをリフレッシュ

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ConditionSeeder::class);
    }

    /**
     * ユーザーがチャットメッセージを送受信できることをテストする
     *
     * @return void
     */
    public function test_users_can_send_and_receive_chat_messages()
    {
        Storage::fake('public'); // publicディスクをモックする

        // データの準備
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        $soldItem = SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        // --- 購入者からのメッセージ送信 ---
        $this->actingAs($buyer);

        $messageText = '購入者からのメッセージです';
        $imageFile = UploadedFile::fake()->image('buyer_message.jpg');

        $response = $this->postJson(route('api.chat.store', $item->id), [
            'message' => $messageText,
            'image' => $imageFile,
        ]);

        // データベースにメッセージが保存されたことを確認
        $this->assertDatabaseHas('chats', [
            'sold_item_id' => $soldItem->id,
            'sender_id' => $buyer->id,
            'message' => $messageText,
            'image_path' => 'chat_images/' . $imageFile->hashName(),
        ]);
        // ストレージに画像ファイルが保存されたことを確認
        Storage::disk('public')->assertExists('chat_images/' . $imageFile->hashName());
        Storage::disk('public')->assertExists('chat_images/' . $imageFile->hashName());
        $response->assertStatus(201);

        // 送信直後はread_atがnullであることを確認
        $buyerChat = Chat::where('sender_id', $buyer->id)->where('sold_item_id', $soldItem->id)->first();
        $this->assertNotNull($buyerChat); // 確実にチャットが存在することを確認
        $this->assertNull($buyerChat->read_at);


        // --- 出品者からのメッセージ送信 ---
        $this->actingAs($seller);

        $messageTextSeller = '出品者からの返信です';
        $imageFileSeller = UploadedFile::fake()->image('seller_reply.png');

        $responseSeller = $this->postJson(route('api.chat.store', $item->id), [
            'message' => $messageTextSeller,
            'image' => $imageFileSeller,
        ]);

        $this->assertDatabaseHas('chats', [
            'sold_item_id' => $soldItem->id,
            'sender_id' => $seller->id,
            'message' => $messageTextSeller,
            'image_path' => 'chat_images/' . $imageFileSeller->hashName(),
        ]);
        Storage::disk('public')->assertExists('chat_images/' . $imageFileSeller->hashName());
        Storage::disk('public')->assertExists('chat_images/' . $imageFileSeller->hashName());
        $responseSeller->assertStatus(201);

        // --- 出品者がチャット画面を開いた後（APIを取得した後）、購入者からのメッセージが既読になっていることを確認 ---
        $responseChatApi = $this->getJson(route('api.chat.index', $item->id));
        $responseChatApi->assertStatus(200);
        $responseChatApi->assertJsonFragment(['message' => $messageText]);
        $responseChatApi->assertJsonFragment(['message' => $messageTextSeller]);
        // 画像検証はJSON内のimage_path等で行う必要があるが、Fragmentでファイル名が含まれているか確認
        $this->assertStringContainsString($imageFile->hashName(), $responseChatApi->content());
        $this->assertStringContainsString($imageFileSeller->hashName(), $responseChatApi->content());

        $buyerChat->refresh(); // データベースから最新の状態を再読み込み
        $this->assertNotNull($buyerChat->read_at); // read_atがnullではないことを確認
    }

    /**
     * 購入者がチャット画面で「取引を完了する」ボタンを見ることができる
     * @return void
     */
    public function test_buyer_can_see_complete_transaction_button()
    {
        // データの準備
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        // 購入者としてログインし、チャットページにアクセス
        $this->actingAs($buyer);
        $response = $this->get(route('chat.index', $item->id));

        // 「取引を完了する」ボタンが表示されていることを確認
        $response->assertStatus(200);
        $response->assertSee('取引を完了する');
    }

    /**
     * 出品者は購入者が評価するまで「取引を完了する」ボタンを見ることができない
     * @return void
     */
    public function test_seller_cannot_see_complete_button_until_buyer_rates()
    {
        // データの準備
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        // 出品者としてログインし、チャットページにアクセス
        $this->actingAs($seller);
        $response = $this->get(route('chat.index', $item->id));

        // 「取引を完了する」ボタンが表示されていないことを確認
        $response->assertStatus(200);
        $response->assertDontSee('取引を完了する');
        // モーダルが自動で開くためのスクリプトがないことを確認
        $response->assertDontSee('openRatingModal();');
    }

    /**
     * 出品者は購入者の評価後、評価モーダルが自動で開くスクリプトを確認できる
     * @return void
     */
    public function test_seller_sees_rating_modal_script_after_buyer_rates()
    {
        // データの準備
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        $soldItem = SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();
        // 購入者による評価を作成
        \App\Models\Rating::factory()->for($soldItem)->create([
            'rater_id' => $buyer->id,
            'rated_user_id' => $seller->id,
        ]);

        // 出品者としてログインし、チャットページにアクセス
        $this->actingAs($seller);
        $response = $this->get(route('chat.index', $item->id));

        // 評価モーダルを開くJavaScriptが含まれていることを確認
        $response->assertStatus(200);
        $response->assertSee('openRatingModal();', false); // `false`でエスケープを無効に
    }

    /**
     * チャット送信時のバリデーションをテストする
     *
     * @return void
     */
    public function test_chat_validation()
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        $soldItem = SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        $this->actingAs($buyer);

        // 1. 本文が未入力の場合
        $response = $this->postJson(route('api.chat.store', $item->id), [
            'message' => '',
        ]);
        $response->assertJsonValidationErrors(['message']);

        // 2. 画像が.pngまたは.jpeg形式以外の場合 (例: gif)
        $response = $this->postJson(route('api.chat.store', $item->id), [
            'message' => 'Valid message',
            'image' => UploadedFile::fake()->create('test.gif'),
        ]);

        $response->assertJsonValidationErrors(['image']);

        // 3. 本文が401文字以上の場合
        $longMessage = str_repeat('a', 401);
        $response = $this->postJson(route('api.chat.store', $item->id), [
            'message' => $longMessage,
        ]);
        $response->assertJsonValidationErrors(['message']);
    }

    /**
     * メッセージ編集機能をテストする
     *
     * @return void
     */
    public function test_chat_edit()
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        $soldItem = SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        // 購入者がメッセージを送信
        $chat = Chat::create([
            'sender_id' => $buyer->id,
            'sold_item_id' => $soldItem->id,
            'message' => 'Original Message',
        ]);

        $this->actingAs($buyer);

        // メッセージ更新
        $updatedMessage = 'Updated Message';
        $response = $this->patchJson(route('api.chat.update', $chat->id), [
            'message' => $updatedMessage,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'message' => $updatedMessage,
        ]);

        // 他のユーザー（出品者）が編集しようとすると403エラー
        $this->actingAs($seller);
        $response = $this->patchJson(route('api.chat.update', $chat->id), [
            'message' => 'Hacked Message',
        ]);
        $response->assertStatus(403);
    }

    /**
     * メッセージ削除機能をテストする
     *
     * @return void
     */
    public function test_chat_delete()
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = Item::factory()->for($seller, 'seller')->create();
        $soldItem = SoldItem::factory()->for($item)->for($buyer, 'buyer')->create();

        // 購入者がメッセージを送信
        $chat = Chat::create([
            'sender_id' => $buyer->id,
            'sold_item_id' => $soldItem->id,
            'message' => 'Message to delete',
        ]);

        $this->actingAs($buyer);

        // メッセージ削除
        $response = $this->deleteJson(route('api.chat.destroy', $chat->id));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('chats', [
            'id' => $chat->id,
        ]);

        // 他のユーザー（出品者）が削除しようとすると403エラー
        $chat2 = Chat::create([
            'sender_id' => $buyer->id,
            'sold_item_id' => $soldItem->id,
            'message' => 'Message to delete 2',
        ]);

        $this->actingAs($seller);
        $response = $this->deleteJson(route('api.chat.destroy', $chat2->id));
        $response->assertStatus(403);
        $this->assertDatabaseHas('chats', [
            'id' => $chat2->id,
        ]);
    }

    /**
     * 取引一覧が最新メッセージ順にソートされていることをテストする (FN004)
     *
     * @return void
     */
    public function test_transaction_list_sort_order()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // 取引1: 古いメッセージ
        $seller1 = User::factory()->create();
        $item1 = Item::factory()->for($seller1, 'seller')->create();
        $soldItem1 = SoldItem::factory()->for($item1)->for($user, 'buyer')->create(['created_at' => now()->subDays(5)]);
        Chat::factory()->create([
            'sender_id' => $user->id,
            'sold_item_id' => $soldItem1->id,
            'message' => 'Old message',
            'created_at' => now()->subDays(3),
        ]);

        // 取引2: 新しいメッセージ
        $seller2 = User::factory()->create();
        $item2 = Item::factory()->for($seller2, 'seller')->create();
        $soldItem2 = SoldItem::factory()->for($item2)->for($user, 'buyer')->create(['created_at' => now()->subDays(4)]);
        Chat::factory()->create([
            'sender_id' => $user->id,
            'sold_item_id' => $soldItem2->id,
            'message' => 'New message',
            'created_at' => now()->subDays(1),
        ]);

        // 取引3: メッセージなし（取引作成日時が最新）
        $seller3 = User::factory()->create();
        $item3 = Item::factory()->for($seller3, 'seller')->create();
        $soldItem3 = SoldItem::factory()->for($item3)->for($user, 'buyer')->create(['created_at' => now()]);

        // ログインしてチャットページ（どの取引でも良い）にアクセス
        $this->actingAs($user);
        $response = $this->get(route('chat.index', $soldItem1->item->id));

        $response->assertStatus(200);

        // ビューに渡された `sidebar` データの `otherTransactions` を確認
        $otherTransactions = $response->viewData('sidebar')['otherTransactions'];

        // $soldItem1を表示中なので、リストには $soldItem3, $soldItem2 が含まれる
        $this->assertEquals($soldItem3->id, $otherTransactions->first()->id);
        $this->assertEquals($soldItem2->id, $otherTransactions->skip(1)->first()->id);

        // 念のため、別の取引($soldItem3)を表示して、全取引の順序を確認
        $response = $this->get(route('chat.index', $soldItem3->item->id));
        $otherTransactions = $response->viewData('sidebar')['otherTransactions'];

        // 期待順序: 取引2 (1日前) -> 取引1 (3日前)
        $this->assertEquals($soldItem2->id, $otherTransactions->first()->id);
        $this->assertEquals($soldItem1->id, $otherTransactions->skip(1)->first()->id);
    }

    /**
     * チャット画面のサイドバーから他の取引へ遷移できることをテストする (FN003)
     *
     * @return void
     */
    public function test_navigation_to_other_transaction()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // 取引1 (現在表示する取引)
        $seller1 = User::factory()->create();
        $item1 = Item::factory()->for($seller1, 'seller')->create(['name' => 'Item 1']);
        $soldItem1 = SoldItem::factory()->for($item1)->for($user, 'buyer')->create();

        // 取引2 (遷移先の取引)
        $seller2 = User::factory()->create();
        $item2 = Item::factory()->for($seller2, 'seller')->create(['name' => 'Item 2']);
        $soldItem2 = SoldItem::factory()->for($item2)->for($user, 'buyer')->create();

        // ログインして取引1のチャットページにアクセス
        $this->actingAs($user);
        $response = $this->get(route('chat.index', $item1->id));

        $response->assertStatus(200);

        // サイドバーに取引2へのリンクが表示されていることを確認
        $response->assertSee($item2->name);
        $response->assertSee(route('chat.index', $item2->id));

        // 取引2へのリンクをクリックしたときの挙動をシミュレート（GETリクエスト）
        $response2 = $this->get(route('chat.index', $item2->id));
        $response2->assertStatus(200);

        // 取引2のページが表示されていることを確認（商品名などで判断）
        $response2->assertSee($item2->name);
    }
}
