<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\G7\Forum\Addon\Http\Controllers\ReactionController;
use Plugins\G7\Forum\Addon\Support\ReactionStore;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 추천(업·다운) 동작 — 1.3.0
 *
 * 확인 범위: 전환·취소, 허용 값 검사, 본인 글·본인 댓글 투표 거부.
 * 라우트는 설치 상태에서만 등록되므로 컨트롤러를 직접 호출해 상태 코드와 저장 결과를 본다.
 */
class VoteTest extends PluginTestCase
{
    private function vote(?\App\Models\User $user, string $targetType, int $id, string $reaction): int
    {
        $request = $this->requestAs($user, [], ['reaction' => $reaction]);

        return $this->statusOf(fn () => app(ReactionController::class)->toggle($request, $targetType, $id));
    }

    /**
     * 저장된 표를 그대로 돌려준다(없으면 null).
     */
    private function storedVote(string $targetType, int $id, int $userId): ?string
    {
        return DB::table(ReactionStore::TABLE)
            ->where('target_type', $targetType)
            ->where('target_id', $id)
            ->where('user_id', $userId)
            ->value('reaction');
    }

    // ── 허용 값 ──────────────────────────────────────────────

    public function test_up_and_down_are_the_only_allowed_values(): void
    {
        $this->assertSame(['up', 'down'], ReactionStore::REACTIONS);
        $this->assertTrue(ReactionStore::isValidReaction('up'));
        $this->assertTrue(ReactionStore::isValidReaction('down'));
    }

    public function test_old_emoji_values_are_rejected_with_422(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);
        $voter = $this->createUserWithRole('user');

        foreach (['like', 'love', 'haha', 'wow', 'sad'] as $old) {
            $this->assertSame(422, $this->vote($voter, 'posts', $postId, $old), $old);
        }
    }

    public function test_unknown_value_is_rejected_with_422(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);

        $this->assertSame(422, $this->vote($this->createUserWithRole('user'), 'posts', $postId, 'sideways'));
    }

    // ── 전환·취소 ────────────────────────────────────────────

    public function test_up_then_down_switches_the_single_vote(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        // 작성자가 아닌 사람이 투표해야 하므로 글은 비회원 작성(user_id = null)으로 둔다.
        $postId = $this->createPost($board);
        $voter = $this->createUserWithRole('user');

        $this->assertSame(200, $this->vote($voter, 'posts', $postId, 'up'));
        $this->assertSame('up', $this->storedVote('post', $postId, $voter->id));

        $this->assertSame(200, $this->vote($voter, 'posts', $postId, 'down'));
        $this->assertSame('down', $this->storedVote('post', $postId, $voter->id));

        // 1인 1표 — 전환이지 추가가 아니다.
        $this->assertSame(1, DB::table(ReactionStore::TABLE)
            ->where('target_type', 'post')->where('target_id', $postId)->count());
    }

    public function test_clicking_the_same_side_again_cancels_the_vote(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);
        $voter = $this->createUserWithRole('user');

        $this->assertSame(200, $this->vote($voter, 'posts', $postId, 'up'));
        $this->assertSame(200, $this->vote($voter, 'posts', $postId, 'up'));

        $this->assertNull($this->storedVote('post', $postId, $voter->id));
        $this->assertSame(0, DB::table(ReactionStore::TABLE)
            ->where('target_type', 'post')->where('target_id', $postId)->count());
    }

    public function test_summary_reports_up_and_down_counts_separately(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);

        $a = $this->createUserWithRole('user');
        $b = $this->createUserWithRole('user');

        $this->vote($a, 'posts', $postId, 'up');
        $this->vote($b, 'posts', $postId, 'down');

        $summary = ReactionStore::summary('post', $postId, $a->id);

        $this->assertSame(1, $summary['counts']['up']);
        $this->assertSame(1, $summary['counts']['down']);
        $this->assertSame(2, $summary['total']);
        $this->assertSame('up', $summary['mine']);
    }

    // ── 본인 투표 거부 ───────────────────────────────────────

    public function test_author_cannot_vote_on_own_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, ['user_id' => $author->id, 'author_name' => null]);

        $this->assertSame(403, $this->vote($author, 'posts', $postId, 'up'));
        $this->assertNull($this->storedVote('post', $postId, $author->id));
    }

    public function test_author_cannot_vote_on_own_comment(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $postId = $this->createPost($board);
        $author = $this->createUserWithRole('user');
        $commentId = $this->createComment($board, $postId, ['user_id' => $author->id, 'author_name' => null]);

        $this->assertSame(403, $this->vote($author, 'comments', $commentId, 'up'));
        $this->assertNull($this->storedVote('comment', $commentId, $author->id));
    }

    public function test_other_member_can_vote_on_someone_elses_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $author = $this->createUserWithRole('user');
        $postId = $this->createPost($board, ['user_id' => $author->id, 'author_name' => null]);
        $voter = $this->createUserWithRole('user');

        $this->assertSame(200, $this->vote($voter, 'posts', $postId, 'up'));
        $this->assertSame('up', $this->storedVote('post', $postId, $voter->id));
    }

    public function test_guest_post_has_no_owner_so_anyone_may_vote(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        // createPost 기본값은 user_id = null (비회원 글).
        $postId = $this->createPost($board);

        $this->assertSame(200, $this->vote($this->createUserWithRole('user'), 'posts', $postId, 'down'));
    }

    // ── 비회원 ───────────────────────────────────────────────

    public function test_guest_cannot_vote(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('user', $board);
        $this->grant('guest', $board);
        $postId = $this->createPost($board);

        // 라우트의 auth:sanctum 을 태우지 않는 직접 호출이므로 컨트롤러 자체 방어선을 본다.
        $this->assertSame(401, $this->vote(null, 'posts', $postId, 'up'));
    }
}
