@extends('layouts.app')

@section('css')
<link rel="stylesheet" href="{{ asset('css/chat.css') }}">
@endsection

@section('content')
<div class="chat-container" id="chat-container"
    data-item-id="{{ $transaction['item']->id }}"
    data-sold-item-id="{{ $transaction['soldItem']->id }}"
    data-user-id="{{ Auth::id() }}">

    <div class="chat-sidebar">
        <div class="chat-sidebar__list">
            <p class="chat-sidebar__list-title">その他の取引</p>
            @foreach($sidebar['otherTransactions'] as $otherTransaction)
            <a href="{{ route('chat.index', $otherTransaction->item->id) }}" class="chat-sidebar__item">
                <span class="chat-sidebar__item-name">{{ $otherTransaction->item->name }}</span>
            </a>
            @endforeach
        </div>
    </div>

    <div class="chat-main">
        <div class="chat-user-header">
            <div class="chat-user-header__left">
                @if($transaction['otherUser']->profile && $transaction['otherUser']->profile->img_url)
                <img src="{{ asset('storage/profile_images/' . $transaction['otherUser']->profile->img_url) }}" alt="プロフィール画像" class="chat-user-header__image">
                @else
                <img src="{{ asset('images/placeholder.png') }}" alt="プロフィール画像" class="chat-user-header__image">
                @endif
                <h1 class="chat-user-header__name">{{ $transaction['otherUser']->name }}さんとの<span class="responsive-break"></span>取引画面</h1>
                <!-- <div class="chat-user-header__rating">
                    <span class="chat-user-header__rating-stars">
                        @for($i = 1; $i <= 5; $i++)
                            @if($i <=$transaction['otherUser']->average_rating)
                            ★
                            @else
                            ☆
                            @endif
                            @endfor
                    </span>
                    <span class="chat-user-header__rating-value">{{ $transaction['otherUser']->average_rating }}</span>
                </div> -->
            </div>
            @if($page['isBuyer'])
            <div class="chat-user-header__right">
                <button type="button" class="chat-user-header__complete-button" onclick="openRatingModal()">取引を完了する</button>
            </div>
            @elseif($page['shouldOpenModal'])
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    openRatingModal();
                });
            </script>
            @endif
        </div>

        <div class="chat-item-info">
            <div class="chat-item-info__image-wrapper">
                @if($transaction['item']->img_url)
                <img src="{{ asset('storage/item_images/' . $transaction['item']->img_url) }}" alt="商品画像" class="chat-item-info__image">
                @else
                <img src="{{ asset('images/placeholder.png') }}" alt="商品画像" class="chat-item-info__image">
                @endif
            </div>
            <div class="chat-item-info__details">
                <h2 class="chat-item-info__name">{{ $transaction['item']->name }}</h2>
                <p class="chat-item-info__price">¥{{ number_format($transaction['item']->price) }}</p>
            </div>
        </div>

        {{-- Messages container for JS --}}
        <div class="chat-messages" id="chat-messages">
            {{-- Messages will be loaded here via JS --}}
        </div>

        <div class="chat-footer">
            <div class="chat-footer__errors" id="chat-errors" style="display:none;"></div>

            <form id="chat-form" class="chat-form">
                <div class="chat-form__input-area">
                    <textarea name="message" id="chat-message-input" class="chat-form__textarea" placeholder="取引メッセージを記入してください"></textarea>
                    <label for="chat-image" class="chat-form__image-label">
                        画像を追加
                        <input type="file" name="image" id="chat-image" class="chat-form__image-input" accept="image/png, image/jpeg">
                    </label>
                </div>
                <button type="submit" class="chat-form__submit-button">
                    <img src="{{ asset('images/send-icon.png') }}" alt="送信" class="chat-form__submit-icon">
                </button>
            </form>
        </div>

        {{-- Rating Form & Modal --}}
        <form action="{{ route('rating.store', $transaction['soldItem']->id) }}" method="POST">
            @csrf
            @include('components.rating-modal', ['item' => $transaction['item']])
        </form>
    </div>
</div>
@endsection

@section('js')
<script src="{{ asset('js/chat.js') }}?v={{ time() }}"></script>
@endsection