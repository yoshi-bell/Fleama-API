<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Item;
use App\Http\Requests\ChatRequest;
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
        $chats = $soldItem->chats()
            ->with(['sender.profile'])
            ->latest()
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
