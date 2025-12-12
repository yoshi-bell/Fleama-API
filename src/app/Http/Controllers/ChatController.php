<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Item;
use App\Models\SoldItem;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function index(Item $item)
    {
        $user = Auth::user();
        $soldItem = $item->soldItem;
        if (!$soldItem) {
            abort(404);
        }

        $soldItem->load(['ratings', 'buyer.profile', 'item.seller.profile']);

        if ($user->id !== $soldItem->buyer_id && $user->id !== $item->seller_id) {
            abort(403);
        }

        // Note: Chat messages are now fetched via API in the frontend.

        // サイドバー用の他の取引を取得
        $otherTransactions = SoldItem::where(function ($query) use ($user) {
            $query->where('buyer_id', $user->id)
                ->orWhereHas('item', function ($q) use ($user) {
                    $q->where('seller_id', $user->id);
                });
        })
            ->whereDoesntHave('ratings', function ($query) use ($user) {
                $query->where('rater_id', $user->id);
            })
            ->where('id', '!=', $soldItem->id) // 現在表示中の取引は除外
            ->with(['item', 'chats'])
            ->get()
            ->sortByDesc(function ($soldItem) use ($user) {
                $latestChat = $soldItem->chats->sortByDesc('created_at')->first();
                return $latestChat ? $latestChat->created_at : $soldItem->created_at;
            });

        // 取引相手の情報を取得
        if ($user->id === $soldItem->buyer_id) {
            $otherUser = $soldItem->item->seller;
        } else {
            $otherUser = $soldItem->buyer;
        }

        // --- ビューに渡すデータを整理 ---
        $isBuyer = $user->id === $soldItem->buyer_id;
        $isSeller = $user->id === $item->seller_id;
        $buyerRating = $soldItem->ratings->where('rater_id', $soldItem->buyer_id)->first();
        $sellerRating = $soldItem->ratings->where('rater_id', $item->seller_id)->first();

        $page = [
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'shouldOpenModal' => $isSeller && $buyerRating && !$sellerRating,
        ];

        $transaction = [
            'item' => $item,
            'soldItem' => $soldItem,
            'otherUser' => $otherUser,
        ];

        $sidebar = [
            'otherTransactions' => $otherTransactions,
        ];

        return view('chat.index', [
            'page' => $page,
            'transaction' => $transaction,
            'sidebar' => $sidebar,
        ]);
    }
}
