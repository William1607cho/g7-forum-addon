<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Plugins\G7\Forum\Addon\Repositories\ActivitySortedPostRepository;
use Plugins\G7\Forum\Addon\Support\ActivityTime;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 포럼 목록 최근활동순 강제 정렬 (1.2.0)
 *
 * 확인하는 것 셋:
 *  1. 활동 시각의 정의 — 글 작성 시각과 삭제되지 않은 댓글 작성 시각 중 가장 늦은 값
 *  2. forum 판정 분기 — forum 이 아닌 게시판은 코어 정렬(요청/게시판 설정)을 그대로 쓴다
 *  3. 동률 처리 — 활동 시각이 같으면 id 내림차순
 *
 * 정렬 결과는 Repository 를 컨테이너에서 꺼내 직접 호출해 확인한다(라우트 미경유).
 */
class ActivitySortTest extends PluginTestCase
{
    /**
     * 목록 조회 결과에서 원글 id 만 순서대로 뽑는다.
     *
     * @return array<int, int>
     */
    private function listedIds(string $slug, int $perPage = 20, array $filters = []): array
    {
        $ids = [];

        foreach (app(ActivitySortedPostRepository::class)->paginate($slug, $filters, $perPage) as $post) {
            $ids[] = (int) $post->id;
        }

        return $ids;
    }

    private function at(string $time): string
    {
        return '2026-09-'.$time;
    }

    // ── 1. 활동 시각의 정의 ──────────────────────────────────

    public function test_latest_comment_decides_activity_time(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        // 오래된 글에 새 댓글이 달리면 그 글이 맨 위로 올라온다.
        $old = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $new = $this->createPost($board, ['created_at' => $this->at('10 00:00:00')]);
        $this->createComment($board, $old, ['created_at' => $this->at('20 00:00:00')]);

        $this->assertSame([$old, $new], $this->listedIds($board->slug));
    }

    public function test_post_without_comments_falls_back_to_its_own_created_at(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $older = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $newer = $this->createPost($board, ['created_at' => $this->at('02 00:00:00')]);

        // 댓글이 한 건도 없어도 값이 없는 글은 생기지 않는다(= 뒤로 밀리지 않는다).
        $this->assertSame([$newer, $older], $this->listedIds($board->slug));
    }

    public function test_soft_deleted_comment_is_excluded(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $a = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $b = $this->createPost($board, ['created_at' => $this->at('02 00:00:00')]);
        $this->createComment($board, $a, [
            'created_at' => $this->at('20 00:00:00'),
            'deleted_at' => $this->at('21 00:00:00'),
        ]);

        // 삭제된 댓글은 활동으로 세지 않으므로 순서가 되돌아간다.
        $this->assertSame([$b, $a], $this->listedIds($board->slug));
    }

    public function test_comment_update_is_not_activity(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $a = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $b = $this->createPost($board, ['created_at' => $this->at('02 00:00:00')]);
        // 댓글 수정 시각만 최신이고 작성 시각은 옛날이다 — 활동은 작성 시각만 본다.
        $this->createComment($board, $a, [
            'created_at' => $this->at('01 00:00:00'),
            'updated_at' => $this->at('30 00:00:00'),
        ]);

        $this->assertSame([$b, $a], $this->listedIds($board->slug));
    }

    public function test_batch_and_order_agree_on_the_same_post(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $post = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $this->createComment($board, $post, ['created_at' => $this->at('20 00:00:00')]);

        // 표시용 배치 집계와 정렬용 상관 서브쿼리는 같은 값을 내야 한다(S7).
        $batch = ActivityTime::batch((int) $board->id, [$post]);

        $this->assertSame(
            $this->at('20 00:00:00'),
            substr((string) $batch[$post], 0, 19)
        );
    }

    // ── 2. forum 판정 분기 ──────────────────────────────────

    public function test_non_forum_board_keeps_core_ordering(): void
    {
        $board = $this->createBoard('basic');
        $this->grant('guest', $board);

        $old = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $new = $this->createPost($board, ['created_at' => $this->at('10 00:00:00')]);
        $this->createComment($board, $old, ['created_at' => $this->at('20 00:00:00')]);

        // basic 게시판은 코어 기본값(created_at desc) 그대로 — 댓글은 순서를 바꾸지 않는다.
        $this->assertSame([$new, $old], $this->listedIds($board->slug));
    }

    public function test_forum_board_ignores_requested_sort(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $old = $this->createPost($board, ['created_at' => $this->at('01 00:00:00')]);
        $new = $this->createPost($board, ['created_at' => $this->at('10 00:00:00')]);
        $this->createComment($board, $old, ['created_at' => $this->at('20 00:00:00')]);

        // 요청이 작성일 오름차순을 달라고 해도 포럼은 활동순이 강제된다.
        $this->assertSame(
            [$old, $new],
            $this->listedIds($board->slug, 20, ['order_by' => 'created_at', 'order_direction' => 'asc'])
        );
    }

    // ── 3. 동률 처리 ────────────────────────────────────────

    public function test_ties_break_by_id_desc(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $same = $this->at('05 00:00:00');
        $first = $this->createPost($board, ['created_at' => $same]);
        $second = $this->createPost($board, ['created_at' => $same]);
        $third = $this->createPost($board, ['created_at' => $same]);

        $this->assertSame([$third, $second, $first], $this->listedIds($board->slug));
    }

    public function test_page_boundary_has_no_duplicate_or_gap_on_ties(): void
    {
        $board = $this->createBoard('forum');
        $this->grant('guest', $board);

        $same = $this->at('05 00:00:00');
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->createPost($board, ['created_at' => $same]);
        }

        $page1 = $this->listedIds($board->slug, 2);
        $page2 = $this->listedIds($board->slug, 2, ['page' => 2]);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame([], array_intersect($page1, $page2));

        rsort($ids);
        $this->assertSame(array_slice($ids, 0, 4), array_merge($page1, $page2));
    }
}
