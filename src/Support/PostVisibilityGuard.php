<?php

namespace Plugins\G7\Forum\Addon\Support;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\SecretContentGate;

/**
 * 애드온 엔드포인트가 게시글 메타를 내려주기 전, sirsoft-board 와 **동일한 가시성 규칙**을
 * 자체적으로 재검증하는 게이트.
 *
 * 왜 필요한가: 애드온 API 는 sirsoft-board 의 컨트롤러/미들웨어를 거치지 않는다. 인증
 * 여부만 보고 응답하면 추천수·태그·구독여부가 비밀글/블라인드/삭제글의 존재나 내용을
 * 추론하는 우회 채널이 된다(설계문서 §4). 그래서 게시글을 실제로 조회하고, sirsoft-board
 * 가 적용하는 것과 같은 조건으로 열람 가능 여부를 먼저 판정한다.
 *
 * 판정 범위:
 *  - 존재/소프트삭제/게시판 활성 : 완전 반영 (404)
 *  - 게시판별 읽기 권한(ACL)      : `sirsoft-board.{slug}.posts.read` — sirsoft-board 라우트의
 *                                  permission 미들웨어와 같은 판정·같은 순서·같은 응답
 *                                  (비회원 401 / 권한 없는 회원 403). 1.1.1 에서 추가.
 *  - status = blinded / deleted  : 작성자 본인만 통과 (매니저 권한 경로는 TODO)
 *  - is_secret                   : **코어 `SecretContentGate::canView()` 에 위임** (1.3.0)
 *
 * ── 비밀글 판정을 코어에 위임한 이유 (1.3.0) ──────────────────────────
 * 1.2.0 까지는 "작성자 본인만" 이라는 자체 규칙이었다. 그래서 게시판 매니저나
 * `posts.read-secret` 보유자가 본문은 정상적으로 읽는데 애드온 API 만 403 을 돌려주어,
 * 그 사람들에게는 위젯이 통째로 비어 보였다. 코어는 같은 판정을 `SecretContentGate`
 * 한 곳(SSoT)에 모아 두었으므로 규칙을 복제하지 않고 그대로 쓴다 — 작성자 →
 * 비밀번호 검증 → 열람 토큰 → `posts.read-secret` → `manager` 순서도 코어 그대로다.
 *
 * ⚠ 이 클래스는 이 플러그인에서 예외적으로 코어 Eloquent 모델(`Post`)을 쓴다.
 * 게이트가 `Post` 타입을 요구하고, 슬러그를 `route('slug')` 또는 **로드된 `board`
 * 관계**에서만 해석하며 못 얻으면 fail-closed(마스킹)로 떨어지기 때문이다. 애드온
 * 라우트에는 `{slug}` 파라미터가 없으므로 `board` 관계를 반드시 함께 로드해 넘긴다.
 * 나머지 조회는 종전대로 쿼리 빌더를 쓴다.
 */
class PostVisibilityGuard
{
    /**
     * 게시글이 요청자에게 열람 가능한지 확인하고, 불가하면 HTTP 예외로 중단한다.
     *
     * @return object{post: object, board: object} 통과 시 게시글/게시판 행
     */
    public function assertViewable(Request $request, int $postId): object
    {
        $post = DB::table('board_posts')->where('id', $postId)->first();

        // 존재하지 않음 / 소프트 삭제됨 → 404 (삭제글 열람은 뼈대 범위 밖)
        if ($post === null || $post->deleted_at !== null) {
            abort(404, 'Post not found.');
        }

        $board = DB::table('boards')->where('id', $post->board_id)->first();

        if ($board === null || (int) $board->is_active !== 1) {
            abort(404, 'Post not found.');
        }

        // 게시판 읽기 권한 — 블라인드/비밀글보다 먼저 (sirsoft-board 는 미들웨어에서 먼저 막는다).
        $this->assertBoardReadable($request, $board);

        $userId = $request->user()?->id;
        $isAuthor = $userId !== null && (int) $post->user_id === (int) $userId;

        // 블라인드/삭제 상태 → 작성자 본인만 (매니저 권한 경로 TODO)
        if (in_array($post->status, ['blinded', 'deleted'], true) && ! $isAuthor) {
            abort(403, 'This post is not viewable.');
        }

        // 비밀글 → 코어 게이트(SSoT)에 위임. 작성자 판정도 게이트 안에서 먼저 이뤄진다.
        if ((int) $post->is_secret === 1 && ! $this->canViewSecret((int) $post->id, $request)) {
            abort(403, 'This post is secret.');
        }

        return (object) ['post' => $post, 'board' => $board];
    }

    /**
     * 비밀글 원문 열람 권한을 코어 `SecretContentGate` 로 판정한다.
     *
     * 게이트는 `Post` 모델과 슬러그를 필요로 하고, 슬러그를 해석하지 못하면 안전하게
     * false 를 돌려준다. 애드온 라우트에는 `{slug}` 가 없으므로 `board` 관계를 함께
     * 로드해 넘긴다. 모델을 찾지 못하면(경합 등) fail-closed 로 거부한다.
     *
     * @param  int  $postId  board_posts.id
     * @param  Request  $request  HTTP 요청 (열람 확인 토큰 헤더를 여기서 읽는다)
     */
    public function canViewSecret(int $postId, Request $request): bool
    {
        $model = Post::with('board')->find($postId);

        if ($model === null || $model->board === null) {
            return false;
        }

        return app(SecretContentGate::class)->canView($model, $request);
    }

    /**
     * 요청자가 게시판 글 읽기 권한(`sirsoft-board.{slug}.posts.read`)을 가졌는지 확인하고,
     * 없으면 sirsoft-board 의 PermissionMiddleware 와 같은 응답으로 중단한다.
     *
     * - 비회원: 401 `auth.guest_permission_denied`
     * - 회원  : 403 `auth.permission_denied` — 회원에게 401 을 주면 프론트 ApiClient 가
     *           토큰 만료로 보고 강제 로그아웃하므로 반드시 403 이어야 한다.
     *
     * @param  object  $board  boards 행 (slug 필요)
     */
    public function assertBoardReadable(Request $request, object $board): void
    {
        $ability = "sirsoft-board.{$board->slug}.posts.read";
        $user = $request->user();

        if (PermissionHelper::check($ability, $user)) {
            return;
        }

        $params = ['required_permissions' => $ability];

        throw new HttpResponseException(
            $user === null
                ? ResponseHelper::unauthorized('auth.guest_permission_denied', $params)
                : ResponseHelper::forbidden('auth.permission_denied', $params)
        );
    }

    /**
     * 댓글을 추천 대상으로 삼기 전, 댓글 존재/상태 + 소속 게시글 가시성을 재검증한다.
     *
     * 삭제(soft delete)·삭제 상태·블라인드 댓글에는 추천할 수 없다. 통과 조건이면
     * 댓글이 속한 게시글에 대해 `assertViewable()` 을 그대로 태워 게시글 가시성까지 확인한다.
     *
     * @return object{comment: object, post: object, board: object} 통과 시 행들
     */
    public function assertCommentTargetViewable(Request $request, int $commentId): object
    {
        $comment = DB::table('board_comments')->where('id', $commentId)->first();

        if ($comment === null || $comment->deleted_at !== null) {
            abort(404, 'Comment not found.');
        }

        if (in_array($comment->status, ['blinded', 'deleted'], true)) {
            abort(403, 'This comment is not viewable.');
        }

        // 소속 게시글 가시성 재검증 (존재/삭제/블라인드/비밀글/게시판 활성).
        $resolved = $this->assertViewable($request, (int) $comment->post_id);

        return (object) [
            'comment' => $comment,
            'post' => $resolved->post,
            'board' => $resolved->board,
        ];
    }
}
