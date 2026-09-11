<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use Plugins\G7\Forum\Addon\Support\AcceptedReplyState;
use Plugins\G7\Forum\Addon\Support\PostLockState;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;
use Plugins\G7\Forum\Addon\Support\ReactionStore;

/**
 * 포럼 게시글 메타 엔드포인트.
 *
 * `GET /api/plugins/g7-forum-addon/posts/{id}/meta`
 *
 * 게시글 상세 진입 시 sirsoft-board 본문 API 와 병렬로 호출되어(2-call 구조, 설계문서 §3),
 * 추천수·태그·구독여부·베스트답글·잠금상태·고정여부를 내려준다.
 *
 * 응답 봉투는 sirsoft-board 와 동일한 `{success, message, data}` — 프론트에서
 * `{{forum_meta.data.<field>}}` 로 접근한다.
 *
 * 이 빌드(0.1.x)에서 실제로 채워진 필드:
 *  - `is_notice` : 고정(공지) 여부. **값의 원천은 sirsoft-board 의 `board_posts.is_notice`**
 *    이며 애드온은 별도로 저장하지 않고 조회만 반영한다.
 * 나머지(reactions/tags/subscribed/accepted_reply_id/locked)는 각 기능 단계에서 채운다.
 *
 * 응답 전 `PostVisibilityGuard` 로 sirsoft-board 와 동일한 가시성(비밀글/블라인드/삭제/
 * 게시판 활성)을 재검증한다 — 인증만으로 응답하면 안 된다(설계문서 §4).
 */
class PostMetaController extends PublicBaseController
{
    use FormatsBoardDate;

    public function __construct(
        private readonly PostVisibilityGuard $visibilityGuard,
    ) {
        parent::__construct();
    }

    /**
     * @param  int  $id  board_posts.id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        // 가시성 재검증 — 불가 시 여기서 404/403 으로 중단. 통과 시 게시글/게시판 행을 돌려준다.
        $resolved = $this->visibilityGuard->assertViewable($request, $id);

        $userId = Auth::id();
        $isForum = ($resolved->board->type ?? null) === 'forum';

        // 댓글 리액션 요약 — 포럼 게시글일 때만. 이 게시글의 (삭제 안 된) 댓글 id 를 모아
        // 한 번에 집계해 {commentId: {counts, total, mine}} 맵으로 내려준다.
        $commentReactions = [];
        if ($isForum) {
            $commentIds = DB::table('board_comments')
                ->where('post_id', $id)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
            $commentReactions = ReactionStore::summaryForComments($commentIds, $userId);
        }

        // 베스트답글(채택): 포럼 게시글일 때만. 채택 댓글이 삭제/블라인드/무효면
        // resolveForMeta() 가 그 자리에서 null 로 정리(자기치유) — 그 결과를 그대로
        // 따라가므로 이 시점에 유효하지 않은 댓글의 전문을 내려줄 일은 없다.
        $acceptedReplyId = $isForum ? AcceptedReplyState::resolveForMeta($id) : null;

        return $this->success('common.success', [
            // 게시글 리액션 요약: {counts:{like,love,haha,wow,sad}, total, mine}
            'reactions' => $isForum
                ? ReactionStore::summary('post', $id, $userId)
                : ['counts' => array_fill_keys(ReactionStore::REACTIONS, 0), 'total' => 0, 'mine' => null],
            // 댓글 리액션 요약 맵 (포럼 아니면 빈 객체)
            'comment_reactions' => $commentReactions,
            'tags' => [],
            'subscribed' => false,
            'accepted_reply_id' => $acceptedReplyId,
            // 채택된 댓글 전문 — {id, content, author:{uuid,name,avatar}, created_at,
            // created_at_formatted} 또는 null. content 는 g7-comment-editor 가 정화한
            // HTML 을 그대로 반환한다(서버는 재검열하지 않음 — 위젯 쪽 렌더링이 기존
            // 댓글 표시와 동일한 방식(text 바인딩 → g7-comment-editor 재정화 승격)으로
            // 표시해 XSS 방어를 그대로 상속받는다).
            'accepted_reply' => $acceptedReplyId !== null ? $this->buildAcceptedReplyDetails($acceptedReplyId) : null,
            // 잠금(Lock): 포럼형 게시판 + 메타 행 is_locked=1 일 때만 true.
            // 비-포럼 게시판이면 PostLockState 내부 조인이 걸러 항상 false.
            'locked' => PostLockState::isLocked($id),
            // 고정(공지): sirsoft-board board_posts.is_notice 를 그대로 반영 (애드온 미저장).
            'is_notice' => (bool) ($resolved->post->is_notice ?? false),
        ]);
    }

    /**
     * 채택된 댓글의 전문/작성자/시각을 조회합니다.
     *
     * `accepted_reply_id` 가 `resolveForMeta()` 로 이미 유효성(존재·이 게시글 소속·
     * 삭제/블라인드 아님) 검증을 마친 뒤 호출되므로, 여기서는 존재 여부만 방어적으로
     * 다시 확인한다(경합 등 극히 드문 경우 대비).
     *
     * @return array{id: int, content: string, author: array{uuid: string|null, name: string, avatar: string|null}, created_at: string|null, created_at_formatted: string}|null
     */
    private function buildAcceptedReplyDetails(int $commentId): ?array
    {
        $comment = DB::table('board_comments')->where('id', $commentId)->first();

        if ($comment === null) {
            return null;
        }

        $author = null;
        if ($comment->user_id !== null) {
            $user = User::find($comment->user_id);
            if ($user !== null) {
                $isWithdrawn = UserStatus::tryFrom($user->status) === UserStatus::Withdrawn;
                $author = [
                    'uuid' => $user->uuid,
                    'name' => $isWithdrawn ? __('user.withdrawn_user') : $user->name,
                    'avatar' => $isWithdrawn ? null : $user->getAvatarUrl(),
                ];
            }
        }
        if ($author === null) {
            $author = ['uuid' => null, 'name' => $comment->author_name ?? '', 'avatar' => null];
        }

        $dateFormat = g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard');

        return [
            'id' => (int) $comment->id,
            'content' => $comment->content,
            'author' => $author,
            'created_at' => $comment->created_at,
            'created_at_formatted' => $this->formatCreatedAtFormat($comment->created_at, $dateFormat),
        ];
    }
}
