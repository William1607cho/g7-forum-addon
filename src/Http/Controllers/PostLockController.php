<?php

namespace Plugins\G7\Forum\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\G7\Forum\Addon\Support\BoardManagerGate;
use Plugins\G7\Forum\Addon\Support\PostLockState;
use Plugins\G7\Forum\Addon\Support\PostVisibilityGuard;

/**
 * 포럼 게시글 잠금(Lock) 토글 엔드포인트.
 *
 *   POST /api/plugins/g7-forum-addon/posts/{id}/lock
 *   POST /api/plugins/g7-forum-addon/posts/{id}/unlock
 *
 * 잠긴 게시글에는 애드온이 서버에서 새 댓글/답글 작성을 거부한다(CommentLockGuardListener).
 *
 * ── 권한 (1.3.0 에서 변경) ─────────────────────────────────────
 * 라우트의 `auth:sanctum` 이 비회원을 401 로 막고, 컨트롤러가 **게시판 매니저
 * (`sirsoft-board.{slug}.manager`)** 인지 판정해 아니면 403 이다 — 판정은
 * {@see BoardManagerGate}.
 *
 * 1.2.0 까지는 `AdminBaseController`(`auth:sanctum` + `admin` 미들웨어)라 **사이트 관리자
 * (`User::isAdmin()`)만** 잠글 수 있었다. 화면 버튼도 `_global.currentUser.is_admin` 으로
 * 노출했다. 핀(1.3.0)을 게시판 매니저 기준으로 만들면서, 같은 위젯 안에서 두 버튼의
 * 기준이 갈리지 않도록 잠금도 같은 기준으로 맞췄다. 관리자 역할은 리프 권한을 모두
 * 보유하므로 기존 사이트 관리자는 그대로 잠글 수 있다.
 *
 * ── 가시성 재검증 ──────────────────────────────────────────────
 * `/meta` 와 동일하게 `PostVisibilityGuard` 로 존재/삭제/게시판 활성/블라인드/비밀글을
 * 재확인하고, 추가로 소속 게시판이 **포럼형(`forum`)** 인지 확인한다(잠금은 포럼 전용).
 * 비-포럼 게시판 게시글이면 404 로 응답한다(기능 자체를 숨긴다).
 *
 * 비밀글도 잠글 수 있다 — 1.3.0 에서 가드의 비밀글 판정이 코어 `SecretContentGate` 로
 * 바뀌면서, 매니저는 비밀글 원문을 볼 수 있으므로 가드를 통과한다. 별도 분기는 없다.
 */
class PostLockController extends PublicBaseController
{
    public function __construct(
        private readonly PostVisibilityGuard $visibilityGuard,
        private readonly BoardManagerGate $managerGate,
    ) {
        parent::__construct();
    }

    /**
     * 게시글을 잠근다.
     *
     * @param  int  $id  board_posts.id
     */
    public function lock(Request $request, int $id): JsonResponse
    {
        return $this->apply($request, $id, true);
    }

    /**
     * 게시글 잠금을 해제한다.
     *
     * @param  int  $id  board_posts.id
     */
    public function unlock(Request $request, int $id): JsonResponse
    {
        return $this->apply($request, $id, false);
    }

    /**
     * 공통 처리 — 가시성/포럼유형/권한 재검증 후 메타 행 upsert.
     */
    private function apply(Request $request, int $id, bool $locked): JsonResponse
    {
        // 존재/삭제/블라인드/비밀글/게시판활성 재검증 — 불가 시 여기서 404/403.
        $resolved = $this->visibilityGuard->assertViewable($request, $id);

        // 포럼 전용 게이트 — 비-포럼 게시판이면 기능을 숨긴다.
        if (($resolved->board->type ?? null) !== 'forum') {
            return $this->error('g7-forum-addon::messages.lock.not_forum_post', 404);
        }

        if (! $this->managerGate->canManage((string) $resolved->board->slug)) {
            return $this->error('g7-forum-addon::messages.lock.forbidden', 403);
        }

        PostLockState::setLocked($id, (int) $resolved->post->board_id, $locked);

        return $this->success(
            $locked ? 'g7-forum-addon::messages.lock.locked' : 'g7-forum-addon::messages.lock.unlocked',
            ['locked' => $locked]
        );
    }
}
