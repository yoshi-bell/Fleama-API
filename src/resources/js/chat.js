// グローバル関数として定義（Bladeからの呼び出し用）
window.openRatingModal = function () {
    const modal = document.getElementById("rating-modal");
    if (modal) modal.style.display = "flex";
};

document.addEventListener("DOMContentLoaded", function () {
    // ==========================================
    // 1. 変数・定数の定義 (State & DOM)
    // ==========================================
    const chatContainer = document.getElementById("chat-container");
    if (!chatContainer) return;

    // DOM Elements
    const messagesContainer = document.getElementById("chat-messages");
    const chatForm = document.getElementById("chat-form");
    const chatErrors = document.getElementById("chat-errors");
    const chatInput = document.getElementById("chat-message-input");

    // IDs
    const itemId = parseInt(chatContainer.dataset.itemId);
    const currentUserId = parseInt(chatContainer.dataset.userId);
    const soldItemId = parseInt(chatContainer.dataset.soldItemId);

    // State
    let page = 1;
    let hasMore = true;
    let isLoading = false;

    // ==========================================
    // 2. 初期化処理 (Init)
    // ==========================================
    fetchMessages(1, true);
    restoreDraft();

    // ==========================================
    // 3. イベントリスナー定義 (Event Listeners)
    // ==========================================

    // 無限スクロール
    messagesContainer.addEventListener("scroll", handleScroll);

    // メッセージ送信
    chatForm.addEventListener("submit", handleSendMessage);

    // メッセージ編集・削除 (Event Delegation)
    messagesContainer.addEventListener("click", handleMessageActions);

    // 編集フォーム送信 (Event Delegation)
    messagesContainer.addEventListener("submit", handleEditSubmit);

    // ==========================================
    // 4. 関数定義 (Functions)
    // ==========================================

    /**
     * 無限スクロールハンドラー
     */
    function handleScroll() {
        if (messagesContainer.scrollTop === 0 && hasMore && !isLoading) {
            fetchMessages(page + 1, false);
        }
    }

    /**
     * メッセージ送信ハンドラー
     */
    async function handleSendMessage(event) {
        event.preventDefault();
        const formData = new FormData(chatForm);

        try {
            await apiRequest(`/api/v1/items/${itemId}/chats`, "POST", formData);
            chatForm.reset();

            // 下書き削除
            if (soldItemId && currentUserId) {
                const key = getDraftKey(soldItemId, currentUserId);
                localStorage.removeItem(key);
            }

            // 送信後はリセットして再取得
            page = 1;
            hasMore = true;
            messagesContainer.innerHTML = "";
            fetchMessages(1, true);
        } catch (error) {
            handleError(error);
        }
    }

    /**
     * メッセージ操作（編集・削除クリック）ハンドラー
     */
    async function handleMessageActions(event) {
        const clickedElement = event.target;
        const messageElement = clickedElement.closest(".chat-message");
        if (!messageElement) return;
        const chatId = messageElement.dataset.chatId;

        // 編集モード切替
        if (clickedElement.closest(".chat-message__edit-button")) {
            toggleEditMode(messageElement, true);
        } else if (clickedElement.closest(".chat-message__cancel-button")) {
            toggleEditMode(messageElement, false);
        }
        // 削除実行
        else if (clickedElement.closest(".chat-message__delete-button")) {
            if (confirm("本当に削除しますか？")) {
                try {
                    await apiRequest(`/api/v1/chats/${chatId}`, "DELETE");
                    messageElement.remove();
                } catch (error) {
                    alert("削除に失敗しました");
                }
            }
        }
    }

    /**
     * 編集フォーム送信ハンドラー
     */
    async function handleEditSubmit(event) {
        if (!event.target.classList.contains("chat-message__edit-form")) return;

        event.preventDefault();
        const form = event.target;
        const messageElement = form.closest(".chat-message");
        const chatId = messageElement.dataset.chatId;
        const newMessage = form.querySelector('textarea[name="message"]').value;

        try {
            const response = await apiRequest(
                `/api/v1/chats/${chatId}`,
                "PATCH",
                JSON.stringify({ message: newMessage }),
                { "Content-Type": "application/json" }
            );

            // UI更新
            const bubbleText = messageElement.querySelector(
                ".chat-message__text"
            );
            bubbleText.textContent = response.message;
            toggleEditMode(messageElement, false);
        } catch (error) {
            alert("更新に失敗しました");
        }
    }

    /**
     * APIリクエストヘルパー
     */
    async function apiRequest(url, method, body = null, headers = {}) {
        const csrfToken = document.querySelector(
            'meta[name="csrf-token"]'
        ).content;
        const config = {
            method: method,
            headers: {
                "X-CSRF-TOKEN": csrfToken,
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
                ...headers,
            },
        };
        if (body) config.body = body;

        const response = await fetch(url, config);
        if (!response.ok) {
            const errorResponse = await response.json();
            const error = new Error(errorResponse.message || "Error");
            error.data = errorResponse;
            throw error;
        }
        return response.json();
    }

    /**
     * メッセージ取得処理
     */
    async function fetchMessages(pageNum, isScrollToBottom) {
        if (isLoading) return;
        isLoading = true;

        try {
            const apiResponse = await apiRequest(
                `/api/v1/items/${itemId}/chats?page=${pageNum}`,
                "GET"
            );
            const newMessages = apiResponse.data;

            if (newMessages.length === 0) {
                hasMore = false;
                isLoading = false;
                return;
            }

            // メッセージは created_at desc (新しい順) で来る
            // UIには下(新しい) -> 上(古い) で積みたいが、無限スクロールは「過去分を上に足す」
            // ページ1: [Newest ...... Older]
            // 表示: [Older ...... Newest]
            // なので、受け取った配列を reverse して、Prepend する

            // 既存のスクロール高さを保持
            const prevScrollHeight = messagesContainer.scrollHeight;

            const fragment = document.createDocumentFragment();
            // 配列を時系列順 (古い順) に並び替えてから HTML 生成
            // res.data は [Newest, ..., Newer] なので reverse すると [Newer, ..., Newest] (page1の場合)
            // page2: [EvenOlder, ..., Oldest] -> reverse -> [Oldest, ..., EvenOlder]
            // これを Prepend すると [Oldest, EvenOlder][Newer, Newest] となる。OK。

            // 重要: reverse() は破壊的なのでコピーする
            const sortedMessages = [...newMessages].reverse();

            sortedMessages.forEach((chatMessage) => {
                const messageElement = renderMessageItem(chatMessage);
                fragment.appendChild(messageElement);
            });

            // 先頭に追加 (Prepend)
            messagesContainer.insertBefore(
                fragment,
                messagesContainer.firstChild
            );

            // ページ更新
            page = apiResponse.current_page;
            hasMore = apiResponse.next_page_url !== null;

            // スクロール位置の調整
            if (isScrollToBottom) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            } else {
                // 前回の高さとの差分だけスクロールさせることで位置を維持
                messagesContainer.scrollTop =
                    messagesContainer.scrollHeight - prevScrollHeight;
            }
        } catch (error) {
            console.error(error);
        } finally {
            isLoading = false;
        }
    }

    /**
     * HTML生成
     */
    function renderMessageItem(chat) {
        const isSelf = chat.sender_id === currentUserId;
        const div = document.createElement("div");
        div.className = `chat-message ${
            isSelf ? "chat-message--self" : "chat-message--other"
        }`;
        div.dataset.chatId = chat.id;

        // ヘッダー生成
        let headerHtml = "";
        const imgUrl =
            chat.sender && chat.sender.profile && chat.sender.profile.img_url
                ? `/storage/profile_images/${chat.sender.profile.img_url}`
                : "/images/placeholder.png";

        const senderName = chat.sender ? chat.sender.name : "User";

        headerHtml = `
            <div class="chat-message__header">
                    <div class="chat-message__sender-image">
                    <img src="${imgUrl}" alt="${senderName}">
                    </div>
                    <span class="chat-message__sender-name">${senderName}</span>
            </div>
        `;

        // 画像生成
        let imageHtml = "";
        if (chat.image_path) {
            imageHtml = `<img src="/storage/${chat.image_path}" class="chat-message__image">`;
        }

        // アクションボタン生成 (自分のみ)
        let actionsHtml = "";
        let editFormHtml = "";

        if (isSelf) {
            actionsHtml = `
                <div class="chat-message__actions">
                    <button type="button" class="chat-message__edit-button">編集</button>
                    <button type="button" class="chat-message__delete-button">削除</button>
                </div>
            `;
            editFormHtml = `
                <form class="chat-message__edit-form" style="display:none;">
                    <textarea name="message" class="chat-message__edit-textarea">${chat.message}</textarea>
                    <div class="chat-message__edit-buttons">
                        <button type="submit" class="chat-message__update-button">保存</button>
                        <button type="button" class="chat-message__cancel-button">キャンセル</button>
                    </div>
                </form>
            `;
        }

        // 日時フォーマット
        const timeString = new Date(chat.created_at).toLocaleString([], {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
        });

        div.innerHTML = `
            <div class="chat-message__content">
                ${headerHtml}
                <div class="chat-message__bubble">
                    <p class="chat-message__text">${chat.message}</p>
                    ${imageHtml}
                    <div class="chat-message__time">${timeString}</div>
                </div>
                ${actionsHtml}
                ${editFormHtml}
            </div>
        `;

        return div;
    }

    /**
     * 編集モード切替
     */
    function toggleEditMode(messageElement, isEdit) {
        const bubble = messageElement.querySelector(".chat-message__bubble");
        const actions = messageElement.querySelector(".chat-message__actions");
        const editForm = messageElement.querySelector(
            ".chat-message__edit-form"
        );
        const time = messageElement.querySelector(".chat-message__time");

        if (isEdit) {
            bubble.style.display = "none";
            if (actions) actions.style.display = "none";
            if (time) time.style.display = "none";
            editForm.style.display = "block";
        } else {
            bubble.style.display = "block";
            if (actions) actions.style.display = "flex";
            if (time) time.style.display = "block";
            editForm.style.display = "none";
        }
    }

    /**
     * エラーハンドリング
     */
    function handleError(error) {
        if (error.data && error.data.errors) {
            const errorMessages = Object.values(error.data.errors)
                .flat()
                .map(
                    (errorMessage) =>
                        `<p class="chat-form__error">${errorMessage}</p>`
                )
                .join("");

            chatErrors.innerHTML = errorMessages;
            chatErrors.style.display = "block";
            setTimeout(() => {
                chatErrors.style.display = "none";
            }, 3000);
        } else {
            showError("処理に失敗しました");
        }
        console.error(error);
    }

    function showError(errorMessage) {
        chatErrors.innerHTML = `<p class="chat-form__error">${errorMessage}</p>`;
        chatErrors.style.display = "block";
        setTimeout(() => {
            chatErrors.style.display = "none";
        }, 3000);
    }

    /**
     * 下書き復元 (Initで呼び出し)
     */
    function restoreDraft() {
        if (!chatInput || !soldItemId || !currentUserId) return;

        const key = getDraftKey(soldItemId, currentUserId);
        const saved = localStorage.getItem(key);
        if (saved) chatInput.value = saved;

        chatInput.addEventListener("input", () => {
            localStorage.setItem(key, chatInput.value);
        });
    }

    /**
     * LocalStorageキー取得ヘルパー
     */
    function getDraftKey(soldItemId, userId) {
        return `chat_message_for_${soldItemId}_by_user_${userId}`;
    }
});
