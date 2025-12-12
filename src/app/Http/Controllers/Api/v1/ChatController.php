<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Item;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    /**
     * リソースの一覧を表示する。
     * GET /api/v1/items/{item}/chats
     */
    public function index(Item $item)
    {
        $user = Auth::user();
        $soldItem = $item->soldItem;

        if (!$soldItem) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // 権限チェック
        if ($user->id !== $soldItem->buyer_id && $user->id !== $item->seller_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // チャットとリレーションを取得
        // latest() は created_at の降順。
        // 20件ずつページネーション。
        // チャットUIでは通常、古い順に表示したいが、「過去への無限スクロール」の場合、
        // 「最新の20件」（1ページ目）を取得することは、最も新しい20件のメッセージを取得することを意味する。
        // フロントエンド側で表示のために反転させる（あるいは昇順で取得し、最後からページネーションするが、単純なページネーションは通常降順で「最新」を取得する）。
        // 「初期表示で最新の20件をロード」 -> created_at DESC で並べ替え、ページネーション。
        $chats = $soldItem->chats()
            ->with(['sender.profile'])
            ->latest() // created_at desc
            ->paginate(20);

        // 未読メッセージを既読にする
        $soldItem->chats()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($chats, 200);
    }

    /**
     * 新しく作成されたリソースをストレージに保存する。
     * POST /api/v1/items/{item}/chats
     */
    public function store(ChatRequest $request, Item $item)
    {
        $soldItem = $item->soldItem;
        if (!$soldItem) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // 権限チェック
        if (Auth::id() !== $soldItem->buyer_id && Auth::id() !== $item->seller_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('chat_images', 'public');
        }

        $chat = Chat::create([
            'sender_id' => Auth::id(),
            'sold_item_id' => $soldItem->id,
            'message' => $request->message,
            'image_path' => $imagePath,
        ]);

        // レスポンス用にリレーションをロード
        $chat->load('sender.profile');

        return response()->json($chat, 201);
    }

    /**
     * 指定されたリソースを表示する。
     * GET /api/v1/chats/{chat}
     */
    public function show(Chat $chat)
    {
        $user = Auth::user();
        $soldItem = $chat->soldItem;

        // 権限チェック
        if ($user->id !== $soldItem->buyer_id && $user->id !== $soldItem->item->seller_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($chat->load('sender.profile'), 200);
    }

    /**
     * ストレージ内の指定されたリソースを更新する。
     * PUT/PATCH /api/v1/chats/{chat}
     */
    public function update(ChatRequest $request, Chat $chat)
    {
        // 送信者のみ更新可能
        if ($chat->sender_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // 注意: 画像の更新は編集機能の要件に明示的には含まれていません。
        // 通常、チャットの編集はテキストのみです。バリデーションルールは画像を許可していますが、安全のため現状はテキスト更新のみとします。
        // もし画像が送信された場合、更新する実装にする場合はここを変更します。

        $chat->message = $request->message;
        $chat->save();

        return response()->json($chat, 200);
    }

    /**
     * 指定されたリソースをストレージから削除する。
     * DELETE /api/v1/chats/{chat}
     */
    public function destroy(Chat $chat)
    {
        if ($chat->sender_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // 任意: 画像ファイルの削除
        if ($chat->image_path) {
            Storage::disk('public')->delete($chat->image_path);
        }

        $chat->delete();

        return response()->json(['message' => 'Deleted successfully'], 200);
    }
}
