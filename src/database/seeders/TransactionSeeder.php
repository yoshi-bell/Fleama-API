<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\Item;
use App\Models\Rating;
use App\Models\SoldItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Mail;
use App\Mail\TransactionCompleted;
use Illuminate\Support\Facades\App;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 既存のユーザーと商品を取得
        $users = User::all();
        $items = Item::whereDoesntHave('soldItem')->get();

        if ($users->count() < 2 || $items->count() < 1) {
            return;
        }

        // 1. 取引中の商品を作成（チャットあり、SoldItemあり、評価なし）
        $transactionItem = $items->pop();
        $buyer = $users->random();
        $seller = $transactionItem->seller;

        // 自分自身の商品を買わないようにする
        while ($buyer->id === $seller->id) {
            $buyer = $users->random();
        }

        $soldItem = SoldItem::create([
            'item_id' => $transactionItem->id,
            'buyer_id' => $buyer->id,
            'postcode' => '123-4567',
            'address' => '東京都渋谷区...',
            'building' => 'テストビル',
        ]);

        // チャットメッセージ
        // チャットメッセージ（30件作成して無限スクロール検証用にする）
        $now = now();
        for ($i = 0; $i < 30; $i++) {
            Chat::create([
                'sender_id' => ($i % 2 === 0) ? $buyer->id : $seller->id,
                'sold_item_id' => $soldItem->id,
                'message' => 'テストメッセージ ' . ($i + 1) . 'です。無限スクロールの動作確認用です。',
                'created_at' => $now->copy()->subMinutes(30 - $i), // 古い順に作成
                'updated_at' => $now->copy()->subMinutes(30 - $i),
            ]);
        }

        // 2. 取引完了の商品を作成（評価あり）
        if ($items->count() > 0) {
            $completedItem = $items->pop();
            $buyer2 = $users->random();
            $seller2 = $completedItem->seller;

            while ($buyer2->id === $seller2->id) {
                $buyer2 = $users->random();
            }

            $soldItem2 = SoldItem::create([
                'item_id' => $completedItem->id,
                'buyer_id' => $buyer2->id,
                'postcode' => '987-6543',
                'address' => '大阪府大阪市...',
                'building' => 'サンプルマンション',
            ]);

            Rating::create([
                'rater_id' => $buyer2->id,
                'rated_user_id' => $seller2->id,
                'sold_item_id' => $soldItem2->id,
                'rating' => 5,
            ]);

            // ローカル環境の場合のみメールを送信
            if (App::environment('local')) {
                Mail::to($seller2->email)->send(new TransactionCompleted($completedItem, $buyer2));
            }
        }
    }
}
