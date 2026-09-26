<?php

namespace Plugins\G7\Forum\Addon\Tests\Unit;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Plugins\G7\Forum\Addon\Listeners\BoardShowWidgetListener;
use Plugins\G7\Forum\Addon\Support\LayoutAnchors as A;

/**
 * board/show 주입 7자리 — 표식 우선·모양 대비책 테스트 (1.5.0).
 *
 * 트리는 `board/show` 가 인라인된 모양을 줄여 만든 것이다. 세 가지를 만든다.
 *  - legacy : sirsoft-basic 모양(1.4.0 이 찾던 className·if·text 그대로), 표식 없음
 *  - marked : 표식이 있고 **모양은 일부러 바꾼** 트리 — 모양으로는 하나도 못 찾으므로,
 *             7자리가 모두 적용되면 표식으로 찾았다는 뜻이다
 *  - bare   : marked 에서 표식만 뺀 트리 — 표식도 모양도 없어 전부 실패해야 한다
 *
 * Log 파사드는 앱 없이 기록만 하는 가짜로 바꾼다(프레임워크 부팅 없음).
 */
class BoardShowAnchorsTest extends TestCase
{
    private const DEF = "(post?.data?.board?.type === 'forum' ? false : true)";

    private const ROW_IF = '{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false)}}';

    private const TOGGLE_IF = '{{(comment?.replies_count ?? 0) > 0 && (comment?.depth ?? 0) === 0}}';

    private const SETSTATE = '{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? true)})}}';

    private const ICON = "{{_local.collapsedReplies?.[comment?.id] === false ? 'chevron-up' : 'chevron-down'}}";

    private const LABEL = "{{_local.collapsedReplies?.[comment?.id] === false ? '\$t:board.hide_replies' : '\$t:board.show_replies'}} ({{comment?.replies_count}})";

    /** @var object{calls: list<array{0:string,1:string}>} */
    private object $log;

    protected function setUp(): void
    {
        $this->log = new class
        {
            /** @var list<array{0:string,1:string}> */
            public array $calls = [];

            public function __call(string $m, array $a): void
            {
                $this->calls[] = [$m, (string) ($a[0] ?? '')];
            }
        };
        Log::swap($this->log);
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
    }

    private function anchor(bool $marked, string $name): array
    {
        return $marked ? ['data-g7-anchor' => $name] : [];
    }

    /**
     * @param  bool  $marked  표식을 단다
     * @param  bool  $shapeChanged  1.4.0 이 찾던 모양을 바꾼다
     * @param  bool  $title  답글 토글에 title 이 있다(wc-community fork-20260926 모양)
     */
    private function layout(bool $marked, bool $shapeChanged, bool $title = true): array
    {
        $c = $shapeChanged;
        $toggle = [
            'type' => 'basic', 'name' => 'Button', 'if' => self::TOGGLE_IF,
            'props' => ['type' => 'button'] + $this->anchor($marked, A::REPLIES_TOGGLE) + ($title ? ['title' => self::LABEL] : []),
            'actions' => [['type' => 'click', 'handler' => 'setState', 'params' => ['target' => 'local', 'collapsedReplies' => $c ? str_replace(' ?? true', ' ?? true ', self::SETSTATE) : self::SETSTATE]]],
            'children' => [
                ['type' => 'composite', 'name' => 'Icon', 'props' => ['name' => self::ICON]],
                ['type' => 'basic', 'name' => 'Span', 'props' => ['className' => 'sr-only'], 'text' => self::LABEL],
            ],
        ];
        $row = [
            'type' => 'basic', 'name' => 'Div',
            'props' => ['className' => $c ? 'flex gap-4 p-3 rounded-xl' : 'flex gap-3 p-4 rounded-lg'] + $this->anchor($marked, A::COMMENT_ROW),
            'children' => [[
                'type' => 'basic', 'name' => 'P',
                'if' => $c ? '{{!comment?.deleted_at}}' : "{{(!comment?.deleted_at || comment?.is_cascade_deleted) && comment?.status !== 'blinded'}}",
                'props' => ['className' => 'text-gray-700'] + $this->anchor($marked, A::COMMENT_BODY),
                'text' => $c ? '{{comment?.content ?? \'\'}}' : '{{comment?.content}}',
            ]],
        ];
        $comments = [
            'type' => 'basic', 'name' => 'Div',
            'iteration' => ['source' => '{{post?.data?.comments ?? []}}', 'item_var' => 'comment'],
            'children' => [[
                // 모양을 바꿔도 답글 식 자체는 있어야 변환할 거리가 있다(1.4.0 은 통째 문자열 비교라 못 찾는다).
                'type' => 'basic', 'name' => 'Div', 'if' => $c ? '{{(comment?.depth ?? 0) < 1 || _local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false}}' : self::ROW_IF,
                'props' => ['className' => 'border-b'] + $this->anchor($marked, A::REPLIES_ROW),
                'children' => [$row, $c ? ['type' => 'basic', 'name' => 'Button', 'if' => '{{x}}'] + array_diff_key($toggle, ['if' => 1]) : $toggle],
            ]],
        ];
        $input = [
            'type' => 'basic', 'name' => 'Div',
            'if' => $c ? '{{post?.data?.abilities?.can_comment}}' : "{{post?.data?.abilities?.can_write_comments && !post?.data?.deleted_at}}",
            'props' => ['className' => $c ? 'px-4 py-3' : 'p-4 border-t border-gray-200'] + $this->anchor($marked, A::COMMENT_INPUT),
        ];
        $actions = [
            'type' => 'basic', 'name' => 'Div',
            'if' => $c ? '{{!!post?.data}}' : '{{!!post?.data || post?.data?.is_guest_post}}',
            'props' => ['className' => $c ? 'flex items-center gap-2 px-4 py-3' : 'flex items-center justify-end gap-2 px-6 py-4 border-t'] + $this->anchor($marked, A::POST_ACTIONS),
            'children' => [['type' => 'basic', 'name' => 'Button']],
        ];
        $step = [
            'comment' => '댓글 삭제 시 게시글 재조회',
            'if' => $c ? '{{_global.deleteModal.kind === 1}}' : "{{_global.deleteModal.type === 'comment'}}",
            'handler' => 'refetchDataSource',
            'params' => ['dataSourceId' => 'post'],
        ] + $this->anchor($marked, A::DELETE_REFETCH);

        return [
            'layout_name' => 'board/show',
            'data_sources' => [['id' => 'post', 'type' => 'api']],
            'components' => [[
                'type' => 'basic', 'name' => 'Div',
                'children' => [
                    ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'card'], 'children' => [['type' => 'basic', 'name' => 'Div', 'text' => 'body'], $actions]],
                    ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'comments'], 'children' => [$comments, $input]],
                ],
            ]],
            'modals' => [[
                'id' => 'board_delete_modal', 'type' => 'composite', 'name' => 'Modal',
                'children' => [['type' => 'basic', 'name' => 'Button', 'actions' => [['type' => 'click', 'handler' => 'apiCall', 'onSuccess' => [['handler' => 'closeModal'], $step, ['handler' => 'navigate']]]]]],
            ]],
        ];
    }

    private function run_(array $layout): array
    {
        return (new BoardShowWidgetListener)->injectWidget($layout, 3);
    }

    /** @return array<string, mixed>|null */
    private function findId(array $nodes, string $id): ?array
    {
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['id'] ?? null) === $id) {
                return $n;
            }
            if (($f = $this->findId($n['children'] ?? [], $id)) !== null) {
                return $f;
            }
        }

        return null;
    }

    private function card(array $out): array
    {
        return $out['components'][0]['children'][0]['children'];
    }

    private function replyRow(array $out): array
    {
        return $out['components'][0]['children'][1]['children'][0]['children'][0];
    }

    private function summaryOf(array $out): ?string
    {
        return $this->findId($out['components'], 'g7_forum_addon_widget')['props']['data-g7fa-anchors'] ?? null;
    }

    private function allVia(string $via): string
    {
        return implode(',', array_map(fn ($n) => $n.'='.$via.':1', A::ALL));
    }

    public function test_marked_template_applies_all_seven_by_marker_even_with_changed_shapes(): void
    {
        $out = $this->run_($this->layout(true, true));

        $this->assertSame($this->allVia('marker'), $this->summaryOf($out));
        $this->assertSame([], $this->log->calls);

        // 1 위젯은 액션 줄 바로 앞
        $card = $this->card($out);
        $this->assertSame('g7_forum_addon_widget', $card[1]['id']);
        $this->assertSame('post-actions', $card[2]['props']['data-g7-anchor']);
        // 2 잠금 안내는 입력 폼 바로 앞, 폼 if 에 잠금 조건
        $comments = $out['components'][0]['children'][1]['children'];
        $this->assertSame('g7_forum_addon_lock_notice', $comments[1]['id']);
        $this->assertStringContainsString('forum_meta?.data?.locked', $comments[2]['if']);
        // 3·4 댓글 행: 템플릿 className 보존 + 강조식 + DOM id, 본문 뒤 리액션 바
        $row = $this->replyRow($out)['children'][0];
        $this->assertStringStartsWith('flex gap-4 p-3 rounded-xl {{forum_meta?.data?.accepted_reply_id === comment?.id ? ', $row['props']['className']);
        $this->assertSame('g7fa-comment-{{comment?.id}}', $row['props']['id']);
        $this->assertSame('g7_forum_addon_comment_reactions', $row['children'][1]['id']);
        // 5 답글 행 if
        $this->assertStringContainsString('_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] ?? '.self::DEF, $this->replyRow($out)['if']);
        // 7 삭제 모달: 표식 스텝 바로 뒤에 forum_meta 재조회
        $steps = $out['modals'][0]['children'][0]['actions'][0]['onSuccess'];
        $this->assertSame('comment-delete-refetch', $steps[1]['data-g7-anchor']);
        $this->assertSame('forum_meta', $steps[2]['params']['dataSourceId']);
    }

    public function test_marked_toggle_patches_title_label_icon_and_setstate(): void
    {
        $toggle = $this->replyRow($this->run_($this->layout(true, false)))['children'][1];
        $patched = "{{!(_local.collapsedReplies?.[comment?.id] ?? ".self::DEF.") ? '\$t:board.hide_replies' : '\$t:board.show_replies'}} ({{comment?.replies_count}})";

        $this->assertSame($patched, $toggle['props']['title']);
        $this->assertSame($patched, $toggle['children'][1]['text']);
        $this->assertSame("{{!(_local.collapsedReplies?.[comment?.id] ?? ".self::DEF.") ? 'chevron-up' : 'chevron-down'}}", $toggle['children'][0]['props']['name']);
        $this->assertSame('{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? '.self::DEF.')})}}', $toggle['actions'][0]['params']['collapsedReplies']);
    }

    public function test_legacy_template_applies_all_seven_by_shape_with_1_4_0_output(): void
    {
        $out = $this->run_($this->layout(false, false, false));

        $this->assertSame($this->allVia('shape'), $this->summaryOf($out));
        $this->assertSame([], $this->log->calls);
        // 1.4.0 과 같은 결과 문자열
        $this->assertSame('{{(comment?.depth ?? 0) === 0 || !(_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] ?? '.self::DEF.')}}', $this->replyRow($out)['if']);
        $row = $this->replyRow($out)['children'][0];
        $this->assertStringStartsWith('flex gap-3 p-4 rounded-lg {{', $row['props']['className']);
        $toggle = $this->replyRow($out)['children'][1];
        $this->assertArrayNotHasKey('title', $toggle['props']);
        $this->assertStringContainsString('?? '.self::DEF, $toggle['children'][1]['text']);
    }

    public function test_legacy_template_with_title_also_fixes_the_tooltip(): void
    {
        // wc-community fork-20260926 (표식 전) — 1.4.0 은 여기서 title 을 그대로 둬 툴팁이 반대였다.
        $toggle = $this->replyRow($this->run_($this->layout(false, false, true)))['children'][1];

        $this->assertStringNotContainsString('=== false', $toggle['props']['title']);
        $this->assertSame($toggle['children'][1]['text'], $toggle['props']['title']);
    }

    public function test_changed_shapes_without_markers_fail_loudly(): void
    {
        $out = $this->run_($this->layout(false, true));

        $this->assertNull($this->findId($out['components'], 'g7_forum_addon_widget'));
        $levels = array_count_values(array_column($this->log->calls, 0));
        $this->assertSame(1, $levels['error'] ?? 0);
        $this->assertGreaterThanOrEqual(5, $levels['warning'] ?? 0);
    }

    public function test_a_present_marker_disables_the_shape_search_for_that_slot(): void
    {
        // 표식 달린 트리에 1.4.0 모양의 액션 줄을 하나 더 둔다 — 표식 없는 그 줄에는 넣지 않아야 한다.
        $layout = $this->layout(true, true);
        $layout['components'][0]['children'][0]['children'][] = [
            'type' => 'basic', 'name' => 'Div', 'if' => '{{post?.data?.is_guest_post}}',
            'props' => ['className' => 'flex justify-end px-6 py-4 border-t'],
        ];
        $out = $this->run_($layout);

        $this->assertStringStartsWith('post-actions=marker:1,', $this->summaryOf($out));
        $card = $this->card($out);
        $this->assertCount(4, $card); // 본문·위젯·표식 줄·모양 줄 (모양 줄 앞에는 위젯 없음)
        $this->assertSame('flex justify-end px-6 py-4 border-t', $card[3]['props']['className']);
    }

    public function test_running_twice_changes_nothing_more(): void
    {
        foreach ([[true, true], [false, false]] as [$m, $c]) {
            $once = $this->run_($this->layout($m, $c));
            $this->assertSame($once, $this->run_($once));
        }
    }

    public function test_widget_buttons_use_title_and_aria_label(): void
    {
        $out = $this->run_($this->layout(true, true));
        $buttons = [];
        $walk = function (array $n) use (&$walk, &$buttons) {
            if (($n['name'] ?? null) === 'Button') {
                $buttons[] = $n;
            }
            foreach ($n['children'] ?? [] as $c) {
                if (is_array($c)) {
                    $walk($c);
                }
            }
        };
        $walk($this->findId($out['components'], 'g7_forum_addon_widget'));
        $walk($this->findId($out['components'], 'g7_forum_addon_comment_reactions'));

        $this->assertCount(11, $buttons); // 위젯: 추천 2·트로피·핀 2·잠금 2 / 댓글: 추천 2·채택 2
        foreach ($buttons as $b) {
            $this->assertNotSame('', (string) ($b['props']['title'] ?? ''));
            $this->assertNotSame('', (string) ($b['props']['aria-label'] ?? ''));
            foreach ($b['children'] as $c) {
                $this->assertStringNotContainsString('group-hover', (string) ($c['props']['className'] ?? ''));
            }
        }
    }
}
