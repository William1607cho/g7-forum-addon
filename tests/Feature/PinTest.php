<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\G7\Forum\Addon\Http\Controllers\PostLockController;
use Plugins\G7\Forum\Addon\Http\Controllers\PostPinController;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 글 핀(고정) 권한 분기와 보호 필드 — 1.3.0
 *
 * 핀은 코어 공지 값(`board_posts.is_notice`)이다. 여기서 확인하는 것은
 * ① 게시판 매니저만 걸 수 있다 ② 다른 필드가 함께 바뀌지 않는다 ③ 멱등이다
 * ④ 포럼 유형·원글에만 걸린다, 그리고 잠금이 같은 권한 기준으로 맞춰졌는지다.
 */
class PinTest extends PluginTestCase
{
    private function pin(?\App\Models\User $user, int $postId): int
    {
        $request = $this->requestAs($user, [], ['_' => '1']);

        return $this->statusOf(fn () => app(PostPinController::class)->pin($request, $postId));
    }

    private function unpin(?\App\Models\User $user, int $postId): int
    {
        $request = $this->requestAs($user, [], ['_' => '1']);

        return $this->statusOf(fn () => app(PostPinController::class)->unpin($request, $postId));
    }

    private function lock(?\App\Models\User $user, int $postId): int
    {
        $request = $this->requestAs($user, [], ['_' => '1']);

        return $this->statusOf(fn () => app(PostLockController::class)->lock($request, $postId));
    }

    /**
     * 매니저 권한을 가진 사용자를 만든다. 핀·잠금 판정은 `{slug}.manager` 하나다.
     */
    private function manager(\Modules\Sirsoft\Board\Models\Board $board): \App\Models\User
    {
        $this->grant('fa-manager', $board, 'posts.read');
        $this->grant('fa-manager', $board, 'manager');

        return $this->createUserWithRole('fa-manager');
    }

    private function postRow(int $postId): object
    {
        return DB::table('board_posts')->where('id', $postId)->first();
    }

    // ── 권한 분기 ────────────────────────────────────────────

    public function test_board_manager_can_pin(): void
    {
        $board = $this->createBoard('forum');
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->pin($this->manager($board), $postId));
        $this->assertSame(1, (int) $this->postRow($postId)->is_notice);
    }

    public function test_plain_member_cannot_pin(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);

        $this->assertSame(403, $this->pin($this->createUserWithRole('user'), $postId));
        $this->assertSame(0, (int) $this->postRow($postId)->is_notice);
    }

    public function test_post_author_without_manager_cannot_pin_own_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, ['user_id' => $author->id, 'author_name' => null]);

        // 코어 글 수정 API 는 작성자에게 is_notice 를 허용하지만, 애드온 경로는 막는다.
        $this->assertSame(403, $this->pin($author, $postId));
        $this->assertSame(0, (int) $this->postRow($postId)->is_notice);
    }

    public function test_guest_cannot_pin(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);
        $this->grant('user', $board);
        $postId = $this->createPost($board);

        // 라우트의 auth:sanctum 을 태우지 않는 직접 호출이므로 매니저 판정에서 막힌다.
        $this->assertSame(403, $this->pin(null, $postId));
    }

    // ── 유형·대상 게이팅 ─────────────────────────────────────

    public function test_non_forum_board_returns_404(): void
    {
        $board = $this->createBoard('basic');
        $postId = $this->createPost($board);

        $this->assertSame(404, $this->pin($this->manager($board), $postId));
    }

    public function test_reply_cannot_be_pinned(): void
    {
        $board = $this->createBoard('forum');
        $manager = $this->manager($board);
        $rootId = $this->createPost($board);
        $replyId = $this->createPost($board, ['parent_id' => $rootId, 'depth' => 1]);

        $this->assertSame(422, $this->pin($manager, $replyId));
        $this->assertSame(0, (int) $this->postRow($replyId)->is_notice);
    }

    // ── 멱등 / 해제 ──────────────────────────────────────────

    public function test_pinning_twice_is_idempotent(): void
    {
        $board = $this->createBoard('forum');
        $manager = $this->manager($board);
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->pin($manager, $postId));
        $this->assertSame(200, $this->pin($manager, $postId));
        $this->assertSame(1, (int) $this->postRow($postId)->is_notice);
    }

    public function test_unpin_clears_the_notice_flag(): void
    {
        $board = $this->createBoard('forum');
        $manager = $this->manager($board);
        $postId = $this->createPost($board, ['is_notice' => true]);

        $this->assertSame(200, $this->unpin($manager, $postId));
        $this->assertSame(0, (int) $this->postRow($postId)->is_notice);
    }

    // ── 보호 필드 ────────────────────────────────────────────

    public function test_pin_does_not_touch_content_title_or_secret(): void
    {
        $board = $this->createBoard('forum');
        $manager = $this->manager($board);
        $postId = $this->createPost($board, [
            'title' => 'keep me',
            'content' => 'keep my body',
            'content_mode' => 'html',
            'is_secret' => false,
            'status' => 'published',
        ]);

        $before = $this->postRow($postId);

        $this->assertSame(200, $this->pin($manager, $postId));

        $after = $this->postRow($postId);

        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->content, $after->content);
        $this->assertSame((int) $before->is_secret, (int) $after->is_secret);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->created_at, $after->created_at);
        // 바뀌는 것은 공지 값뿐이다(updated_at 은 코어 동작상 갱신된다).
        $this->assertSame(1, (int) $after->is_notice);
    }

    public function test_pin_keeps_secret_flag_on_a_secret_post(): void
    {
        $board = $this->createBoard('forum');
        $manager = $this->manager($board);
        // 매니저는 비밀글 원문을 볼 수 있으므로(1.3.0 SecretContentGate 위임) 가드를 통과한다.
        $this->grant('fa-manager', $board, 'posts.read-secret');
        $postId = $this->createPost($board, ['is_secret' => true]);

        $this->assertSame(200, $this->pin($manager, $postId));

        $after = $this->postRow($postId);
        $this->assertSame(1, (int) $after->is_secret);
        $this->assertSame(1, (int) $after->is_notice);
    }

    // ── 잠금 권한 정렬 (S3) ──────────────────────────────────

    public function test_lock_now_takes_board_manager_permission(): void
    {
        $board = $this->createBoard('forum');
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->lock($this->manager($board), $postId));
    }

    public function test_plain_member_cannot_lock(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);

        $this->assertSame(403, $this->lock($this->createUserWithRole('user'), $postId));
    }
}
