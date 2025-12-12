// グローバル関数として定義（Bladeからの呼び出し用）
window.openRatingModal = function () {
    const modal = document.getElementById("rating-modal");
    if (modal) modal.style.display = "flex";
};

document.addEventListener("DOMContentLoaded", function () {
    const chatContainer = document.getElementById("chat-container");
    if (!chatContainer) return;

    const itemId = chatContainer.dataset.itemId;
    const currentUserId = parseInt(chatContainer.dataset.userId); // ensure int
    const messagesContainer = document.getElementById("chat-messages");
    const chatForm = document.getElementById("chat-form");
    const chatErrors = document.getElementById("chat-errors");

    let page = 1;
    let hasMore = true;
    let isLoading = false;

    // 初期ロード
    fetchMessages(1, true);

    // 無限スクロール
    messagesContainer.addEventListener("scroll", () => {
        if (messagesContainer.scrollTop === 0 && hasMore && !isLoading) {
            fetchMessages(page + 1, false);
        }
    });

    // メッセージ送信
    chatForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const formData = new FormData(chatForm);

        // クライアントバリデーション削除（サーバー側のChatRequestに任せる）
        /*
        const message = formData.get("message");
        const image = formData.get("image");
        if (!message && (!image || image.size === 0)) {
            // alert("メッセージを入力してください"); // Removed per user request
            showError("メッセージを入力してください（本文）"); // Use inline error
            return;
        }
        */

        try {
            await apiRequest(`/api/v1/items/${itemId}/chats`, "POST", formData);
            chatForm.reset();
            // 送信後は最新（一番下）を表示するためにリセット
            page = 1;
            hasMore = true;
            messagesContainer.innerHTML = ""; // クリア
            fetchMessages(1, true);

            // localStorageクリア (FN009)
            const soldItemId = chatContainer.dataset.soldItemId;
            if (soldItemId && currentUserId) {
                localStorage.removeItem(
                    `chat_message_for_${soldItemId}_by_user_${currentUserId}`
                );
            }
        } catch (error) {
            // バリデーションエラーのハンドリング
            if (error.data && error.data.errors) {
                // errors: { message: ["本文を入力してください"], image: ["「.png」または「.jpeg」形式..."] }
                // 全てのエラーメッセージを結合して表示
                const errorMessages = Object.values(error.data.errors)
                    .flat()
                    .map((msg) => `<p class="chat-form__error">${msg}</p>`)
                    .join("");

                chatErrors.innerHTML = errorMessages;
                chatErrors.style.display = "block";
                setTimeout(() => {
                    chatErrors.style.display = "none";
                }, 3000);
            } else {
                showError("送信に失敗しました");
            }
            console.error(error);
        }
    });

    // メッセージ編集・削除 (Event Delegation)
    messagesContainer.addEventListener("click", async (e) => {
        const target = e.target;
        const messageEl = target.closest(".chat-message");
        if (!messageEl) return;
        const chatId = messageEl.dataset.chatId;

        // 編集モード切替
        if (target.closest(".chat-message__edit-button")) {
            toggleEditMode(messageEl, true);
        } else if (target.closest(".chat-message__cancel-button")) {
            toggleEditMode(messageEl, false);
        }
        // 削除実行
        else if (target.closest(".chat-message__delete-button")) {
            if (confirm("本当に削除しますか？")) {
                try {
                    await apiRequest(`/api/v1/chats/${chatId}`, "DELETE");
                    messageEl.remove();
                } catch (error) {
                    alert("削除に失敗しました");
                }
            }
        }
    });

    // 編集フォーム送信 (Event Delegation)
    messagesContainer.addEventListener("submit", async (e) => {
        if (e.target.classList.contains("chat-message__edit-form")) {
            e.preventDefault();
            const form = e.target;
            const messageEl = form.closest(".chat-message");
            const chatId = messageEl.dataset.chatId;
            const newMessage = form.querySelector(
                'textarea[name="message"]'
            ).value;

            try {
                const response = await apiRequest(
                    `/api/v1/chats/${chatId}`,
                    "PATCH",
                    JSON.stringify({ message: newMessage }),
                    {
                        "Content-Type": "application/json",
                    }
                );
                // UI更新
                const bubbleText = messageEl.querySelector(
                    ".chat-message__text"
                );
                bubbleText.textContent = response.message; // サーバーからのレスポンスを使用
                toggleEditMode(messageEl, false);
            } catch (error) {
                alert("更新に失敗しました");
            }
        }
    });

    // APIリクエストヘルパー
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

        const res = await fetch(url, config);
        if (!res.ok) {
            const data = await res.json();
            const error = new Error(data.message || "Error");
            error.data = data; // レスポンスデータをErrorオブジェクトに添付
            throw error;
        }
        return res.json();
    }

    // メッセージ取得
    async function fetchMessages(pageNum, isScrollToBottom) {
        if (isLoading) return;
        isLoading = true;

        try {
            const res = await apiRequest(
                `/api/v1/items/${itemId}/chats?page=${pageNum}`,
                "GET"
            );
            const newMessages = res.data; // paginate response

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

            sortedMessages.forEach((msg) => {
                const el = renderMessageItem(msg);
                fragment.appendChild(el);
            });

            // 先頭に追加 (Prepend)
            messagesContainer.insertBefore(
                fragment,
                messagesContainer.firstChild
            );

            // ページ更新
            page = res.current_page;
            hasMore = res.next_page_url !== null;

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

    function renderMessageItem(chat) {
        const isSelf = chat.sender_id === currentUserId;
        const div = document.createElement("div");
        div.className = `chat-message ${
            isSelf ? "chat-message--self" : "chat-message--other"
        }`;
        div.dataset.chatId = chat.id;

        // Header (Render for both Self and Other)
        let headerHtml = "";
        if (chat.sender && chat.sender.profile) {
            const imgUrl = chat.sender.profile.img_url
                ? `/storage/profile_images/${chat.sender.profile.img_url}`
                : "/images/placeholder.png";
            headerHtml = `
                <div class="chat-message__header">
                     <div class="chat-message__sender-image">
                        <img src="${imgUrl}" alt="${chat.sender.name}">
                     </div>
                     <span class="chat-message__sender-name">${chat.sender.name}</span>
                </div>
            `;
        } else {
            headerHtml = `
                <div class="chat-message__header">
                     <div class="chat-message__sender-image">
                        <img src="/images/placeholder.png" alt="User">
                     </div>
                     <span class="chat-message__sender-name">User</span>
                </div>
            `;
        }

        // Image
        let imageHtml = "";
        if (chat.image_path) {
            imageHtml = `<img src="/storage/${chat.image_path}" class="chat-message__image">`;
        }

        // Actions (Self Only)
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

        div.innerHTML = `
            <div class="chat-message__content">
                ${headerHtml}
                <div class="chat-message__bubble">
                    <p class="chat-message__text">${chat.message}</p>
                    ${imageHtml}
                    <div class="chat-message__time">${new Date(
                        chat.created_at
                    ).toLocaleString([], {
                        year: "numeric",
                        month: "2-digit",
                        day: "2-digit",
                        hour: "2-digit",
                        minute: "2-digit",
                    })}</div>
                </div>
                ${actionsHtml}
                ${editFormHtml}
            </div>
        `;

        return div;
    }

    function toggleEditMode(messageEl, isEdit) {
        const bubble = messageEl.querySelector(".chat-message__bubble");
        const actions = messageEl.querySelector(".chat-message__actions");
        const editForm = messageEl.querySelector(".chat-message__edit-form");
        const time = messageEl.querySelector(".chat-message__time");

        if (isEdit) {
            bubble.style.display = "none";
            if (actions) actions.style.display = "none";
            if (time) time.style.display = "none";
            editForm.style.display = "block";
        } else {
            bubble.style.display = "block";
            if (actions) actions.style.display = "flex"; // flex or block? CSS check
            if (time) time.style.display = "block";
            editForm.style.display = "none";
        }
    }

    function showError(msg) {
        chatErrors.innerHTML = `<p class="chat-form__error">${msg}</p>`;
        chatErrors.style.display = "block";
        setTimeout(() => {
            chatErrors.style.display = "none";
        }, 3000);
    }

    // FN009 LocalStorage Draft Logic
    const chatInput = document.getElementById("chat-message-input");
    if (chatInput) {
        const soldItemId = chatContainer.dataset.soldItemId;
        if (soldItemId && currentUserId) {
            const key = `chat_message_for_${soldItemId}_by_user_${currentUserId}`;
            const saved = localStorage.getItem(key);
            if (saved) chatInput.value = saved;

            chatInput.addEventListener("input", () => {
                localStorage.setItem(key, chatInput.value);
            });
        }
    }
});
