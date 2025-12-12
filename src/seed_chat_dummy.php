<?php

use App\Models\Chat;
use App\Models\SoldItem;
use App\Models\User;
use Carbon\Carbon;

// Find the most recent sold item (transaction)
$soldItem = SoldItem::latest('updated_at')->first();

if (!$soldItem) {
    echo "No sold items found. Please buy an item first.\n";
    exit;
}

$item = $soldItem->item;
$buyer = $soldItem->buyer;
$seller = $item->seller;

echo "Seeding chats for Item ID: {$item->id} (Sold Item ID: {$soldItem->id})\n";
echo "Buyer: {$buyer->name} (ID: {$buyer->id})\n";
echo "Seller: {$seller->name} (ID: {$seller->id})\n";

// Create 30 messages
for ($i = 0; $i < 30; $i++) {
    $sender = ($i % 2 == 0) ? $buyer : $seller; // Alternate senders

    // Create messages with decreasing timestamps (newest first? no, usually created_at increases)
    // We want 30 messages in the past.
    // Infinite scroll loads OLDER messages.
    // So we need enough history.
    // Let's create them from 1 day ago up to now.

    Chat::create([
        'sender_id' => $sender->id,
        'sold_item_id' => $soldItem->id,
        'message' => "Dummy message {$i} for infinite scroll validation. " . \Illuminate\Support\Str::random(10),
        'created_at' => Carbon::now()->subMinutes(30 - $i), // oldest 30 mins ago, newest now
        'updated_at' => Carbon::now()->subMinutes(30 - $i),
    ]);
}

echo "Created 30 dummy messages.\n";
