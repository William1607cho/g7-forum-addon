<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Services\PostService;
use Plugins\G7\Forum\Addon\Support\BoardManagerGate;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;

/**
 * 포럼 게시글 핀(고정) 토글 엔드포인트 (1.3.0 신규).
 *
 *   POST /api/plugins/g7-forum-addon/posts/{id}/pin
 *   POST /api/plugins/g7-forum-addon/posts/{id}/unpin
 *
 * ── 핀은 코어 공지 값이다 ──────────────────────────────────────
 * 애드온은 핀 상태를 따로 저장하지 않는다. 값의 원천은 sirsoft-board 의
 * `board_posts.is_notice` 하나뿐이고, 이 엔드포인트는 그 값을 바꾸기만 한다.
 * 그래서 핀을 걸면 코어 목록이 이미 갖고 있는 "공지는 1페이지 상단" 동작이 그대로
 * 따라오고, `/meta` 의 `is_notice` 도 별도 배선 없이 바뀐다. 애드온 전용 컬럼을 두면
 * 코어 목록 정렬과 두 벌이 되어 서로 어긋난다.
 *
 * ── 왜 코어 수정 API 를 그대로 쓰지 않는가 ──────────────────────
 * 코어 글 수정 API(`PUT /boards/{slug}/posts/{id}`)에는 공지 지정에 대한 별도 권한
 * 검사가 없다 — 작성자면 자기 글을 공지로 만들 수 있다. 관리자급만 핀을 걸게 하려면
 * 애드온이 먼저 판정해야 하고, 그 판정을 통과한 요청만 코어 서비스로 넘긴다.
 *
 * ── 보호 필드 ──────────────────────────────────────────────────
 * `PostService::updatePost()` 에 **`is_notice` 키 하나만** 실어 보낸다. 코어
 * `PostRepository::update()` 는 받은 배열을 그대로 `$post->update()` 에 넘기므로,
 * 배열에 없는 컬럼은 UPDATE 문에 들어가지 않는다. `is_secret`·`content`·`title`·`status`
 * 를 지키는 방법은 "값을 다시 넣지 않는 것"이 아니라 **"키를 싣지 않는 것"** 이다.
 * (`content` 를 싣지 않으므로 `Post::saving` 의 본문 썸네일 재계산도 건너뛴다.)
 *
 * 이 경로를 쓰면 코어와 같은 훅(`post.before_update` / `filter_update_data` /
 * `post.after_update`)이 발화해 SEO 캐시 무효화·재생성과 활동 로그가 따라온다.
 * 대신 `updated_at` 은 바뀐다. 포럼 목록의 "최근 활동" 정렬은 `created_at` 만 보므로
 * (`ActivityTime`) 핀·핀해제가 정렬을 흔들지 않는다.
 *
 * ── 권한 ───────────────────────────────────────────────────────
 * 라우트의 `auth:sanctum` 이 비회원을 401 로 막고, 컨트롤러가 게시판 매니저
 * (`sirsoft-board.{slug}.manager`)인지 판정해 아니면 403 이다 ({@see BoardManagerGate}).
 * 화면 버튼의 노출 조건(`post.data.abilities.can_manage`)과 같은 식별자다.
 *
 * ── 멱등 ───────────────────────────────────────────────────────
 * 이미 고정된 글에 pin 을 다시 걸어도 200 이고 결과 상태는 같다. 응답 `data.is_notice`
 * 가 **요청 후의 상태**이므로 화면은 응답만 보고 배지를 갱신할 수 있다.
 */
class PostPinController extends PublicBaseController
{
    public function __construct(
        private readonly PostVisibilityGuard $visibilityGuard,
        private readonly BoardManagerGate $managerGate,
        private readonly PostService $postService,
    ) {
        parent::__construct();
    }

    /**
     * 게시글을 고정한다(공지로 지정).
     *
     * @param  int  $id  board_posts.id
     */
    public function pin(Request $request, int $id): JsonResponse
    {
        return $this->apply($request, $id, true);
    }

    /**
     * 게시글 고정을 해제한다(공지 해제).
     *
     * @param  int  $id  board_posts.id
     */
    public function unpin(Request $request, int $id): JsonResponse
    {
        return $this->apply($request, $id, false);
    }

    /**
     * 공통 처리 — 가시성/포럼유형/권한 재검증 후 코어 공지 값 갱신.
     */
    private function apply(Request $request, int $id, bool $pinned): JsonResponse
    {
        // 존재/삭제/블라인드/비밀글/게시판활성 재검증 — 불가 시 여기서 404/403.
        $resolved = $this->visibilityGuard->assertViewable($request, $id);

        // 포럼 전용 게이트 — 비-포럼 게시판이면 기능을 숨긴다.
        if (($resolved->board->type ?? null) !== 'forum') {
            return $this->error('g7-forum-addon::messages.pin.not_forum_post', 404);
        }

        $slug = (string) $resolved->board->slug;

        if (! $this->managerGate->canManage($slug)) {
            return $this->error('g7-forum-addon::messages.pin.forbidden', 403);
        }

        // 답글은 공지가 될 수 없다 — 코어 목록의 공지 조회가 `parent_id IS NULL` 을
        // 요구하므로, 답글에 공지를 걸면 어디에도 나타나지 않는 값만 남는다.
        if ($resolved->post->parent_id !== null) {
            return $this->error('g7-forum-addon::messages.pin.not_root_post', 422);
        }

        // 코어 공지 값만 바꾼다. 다른 키는 싣지 않는다(위 "보호 필드" 참고).
        $this->postService->updatePost($slug, $id, ['is_notice' => $pinned]);

        return $this->success(
            $pinned ? 'g7-forum-addon::messages.pin.pinned' : 'g7-forum-addon::messages.pin.unpinned',
            ['is_notice' => $pinned]
        );
    }
}
