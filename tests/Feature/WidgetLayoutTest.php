<?php

namespace Plugins\G7\Forum\Addon\Tests\Feature;

use Plugins\G7\Forum\Addon\Listeners\BoardShowWidgetListener;
use Plugins\G7\Forum\Addon\Tests\PluginTestCase;

/**
 * 위젯 레이아웃 조립 검사 (1.3.0 디자인 정리)
 *
 * 이 리스너는 레이아웃 JSON 을 만들 뿐이라 DB 도 HTTP 도 필요 없다. 그래서 여기서는
 * **만들어진 노드 트리의 모양**만 본다 — 버튼 크기, 아이콘 이름, 툴팁 존재,
 * `$t:` 키가 PHP 변수 보간으로 먹히지 않았는지.
 *
 * 마지막 항목은 실제로 한 번 깨진 적이 있다: 툴팁 문구를 PHP **이중따옴표** 안에서
 * 이어 붙이는 바람에 `$t:` 의 `$t` 가 변수로 해석돼 문구가 `:g7-forum-addon.…` 로
 * 나갔다. 눈으로는 티가 안 나고 화면에서만 드러나는 종류라 검사로 고정해 둔다.
 */
class WidgetLayoutTest extends PluginTestCase
{
    private function node(string $method): array
    {
        $listener = new BoardShowWidgetListener();
        $ref = new \ReflectionMethod($listener, $method);
        $ref->setAccessible(true);

        return $ref->invoke($listener);
    }

    /**
     * 트리를 평탄화해 노드 배열로 돌려준다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $node): array
    {
        $out = [$node];
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $out = array_merge($out, $this->flatten($child));
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buttons(array $tree): array
    {
        return array_values(array_filter(
            $this->flatten($tree),
            fn ($n) => ($n['name'] ?? null) === 'Button'
        ));
    }

    // ── 정사각 버튼 ──────────────────────────────────────────

    public function test_every_widget_button_is_the_same_square(): void
    {
        foreach ($this->buttons($this->node('widgetNode')) as $button) {
            $class = $button['props']['className'] ?? '';
            $this->assertStringContainsString('h-10 w-10', $class);
        }
    }

    public function test_every_comment_bar_button_is_the_same_square(): void
    {
        foreach ($this->buttons($this->node('commentReactionBarNode')) as $button) {
            $class = $button['props']['className'] ?? '';
            $this->assertStringContainsString('h-10 w-10', $class);
        }
    }

    public function test_widget_row_has_four_buttons_two_of_each_toggle_state(): void
    {
        // 업·다운 2개 + 핀 켜짐/꺼짐 2개 + 잠금 켜짐/꺼짐 2개 = 6개가 조립되고,
        // 토글은 `if` 로 한쪽만 렌더되므로 화면에 보이는 것은 4개다.
        $this->assertCount(6, $this->buttons($this->node('widgetNode')));
    }

    // ── 아이콘 ───────────────────────────────────────────────

    public function test_only_icons_present_in_the_template_subset_are_used(): void
    {
        // 템플릿(wc-community)의 Font Awesome 은 Solid 전용 서브셋이라 목록에 없는
        // 이름은 오류 없이 빈칸으로 렌더된다. 여기 적힌 이름만 쓴다.
        $allowed = ['chevron-up', 'chevron-down', 'bullhorn', 'lock', 'circle-check', 'trophy'];

        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->flatten($this->node($method)) as $n) {
                if (($n['name'] ?? null) !== 'Icon') {
                    continue;
                }
                $this->assertContains($n['props']['name'] ?? '', $allowed, $method);
            }
        }
    }

    // ── 툴팁 ─────────────────────────────────────────────────

    public function test_every_button_carries_a_tooltip_and_an_aria_label(): void
    {
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->buttons($this->node($method)) as $button) {
                $this->assertArrayHasKey('aria-label', $button['props'], $method);

                $tooltips = array_filter(
                    $button['children'] ?? [],
                    fn ($c) => is_array($c)
                        && str_contains((string) ($c['props']['className'] ?? ''), 'group-hover:visible')
                );
                $this->assertCount(1, $tooltips, $method.' 버튼에 툴팁이 정확히 1개여야 한다');
            }
        }
    }

    public function test_buttons_do_not_also_set_the_native_title_attribute(): void
    {
        // `title` 을 같이 두면 커스텀 툴팁과 브라우저 툴팁이 겹쳐 두 번 뜬다.
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->buttons($this->node($method)) as $button) {
                $this->assertArrayNotHasKey('title', $button['props'], $method);
            }
        }
    }

    public function test_translation_keys_survive_php_string_building(): void
    {
        // `$t:` 를 PHP 이중따옴표 안에서 이어 붙이면 `$t` 가 변수로 보간돼 조용히
        // `:g7-forum-addon.…` 이 된다. 실제로 한 번 그렇게 깨졌다.
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            $json = json_encode($this->node($method), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString("':g7-forum-addon.", $json, $method);
            $this->assertStringNotContainsString('":g7-forum-addon.', $json, $method);
            $this->assertStringContainsString('$t:g7-forum-addon.', $json, $method);
        }
    }

    // ── 추천 불가 사용자 ─────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>> 추천 버튼(정사각 + 세로 쌓기)만
     */
    private function voteButtons(string $method): array
    {
        return array_values(array_filter(
            $this->buttons($this->node($method)),
            fn ($n) => str_contains((string) ($n['props']['className'] ?? ''), 'flex-col')
        ));
    }

    public function test_vote_buttons_are_disabled_for_guests_and_for_the_author(): void
    {
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->voteButtons($method) as $button) {
                $disabled = (string) ($button['props']['disabled'] ?? '');

                // 비회원 판정과 본인 판정이 모두 비활성 조건에 들어가야 한다.
                $this->assertStringContainsString('!_global.currentUser?.uuid', $disabled, $method);
                $this->assertMatchesRegularExpression(
                    '/is_owner|is_author/',
                    $disabled,
                    $method.' 비활성 조건에 본인 판정이 있어야 한다'
                );
            }
        }
    }

    public function test_own_check_requires_being_signed_in(): void
    {
        // 코어 CommentResource 의 `is_author` 는 `Auth::id() === user_id` 라서 비회원이
        // 비회원 댓글을 볼 때 `null === null` 로 참이 된다. 로그인 여부를 함께 걸지
        // 않으면 비회원에게 "본인 글" 문구가 나간다.
        foreach ($this->voteButtons('commentReactionBarNode') as $button) {
            $disabled = (string) ($button['props']['disabled'] ?? '');
            $this->assertStringContainsString(
                '_global.currentUser?.uuid && comment?.is_author',
                $disabled
            );
        }
    }

    public function test_tooltip_prefers_the_own_post_message_over_the_sign_in_message(): void
    {
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->voteButtons($method) as $button) {
                $tooltip = '';
                foreach ($button['children'] ?? [] as $child) {
                    if (is_array($child)
                        && str_contains((string) ($child['props']['className'] ?? ''), 'group-hover:visible')) {
                        $tooltip = (string) ($child['text'] ?? '');
                    }
                }

                $own = strpos($tooltip, 'self_vote_blocked');
                $login = strpos($tooltip, 'login_required_to_vote');

                $this->assertNotFalse($own, $method);
                $this->assertNotFalse($login, $method);
                $this->assertLessThan($login, $own, $method.' 본인 문구가 먼저 판정돼야 한다');
            }
        }
    }

    public function test_manager_toggles_are_not_affected_by_the_sign_in_check(): void
    {
        // 채택·고정·잠금의 표시 조건은 이번 변경 대상이 아니다.
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->buttons($this->node($method)) as $button) {
                if (str_contains((string) ($button['props']['className'] ?? ''), 'flex-col')) {
                    continue; // 추천 버튼은 제외
                }
                $this->assertArrayNotHasKey('disabled', $button['props'], $method);
                $this->assertStringNotContainsString(
                    'login_required_to_vote',
                    json_encode($button, JSON_UNESCAPED_UNICODE),
                    $method
                );
            }
        }
    }

    // ── 채택 버튼 이동 ───────────────────────────────────────

    public function test_accept_toggle_lives_in_the_comment_bar(): void
    {
        // 추천 2개 + 채택/채택취소 2개
        $this->assertCount(4, $this->buttons($this->node('commentReactionBarNode')));
    }

    // ── 1.4.0 배지 제거·주황·트로피 ───────────────────────────

    public function test_no_text_badge_remains_except_the_locked_one(): void
    {
        $texts = [];
        foreach ($this->flatten($this->node('widgetNode')) as $n) {
            if (($n['name'] ?? null) === 'Span' && is_string($n['text'] ?? null)) {
                $texts[] = $n['text'];
            }
        }
        $json = json_encode($this->node('commentReactionBarNode'), JSON_UNESCAPED_UNICODE);

        // 없앤 배지 키는 어디에도 남지 않는다.
        foreach (['pinned_badge', 'accepted_badge', 'widget_has_accepted'] as $gone) {
            $this->assertStringNotContainsString($gone, implode('|', $texts), $gone);
            $this->assertStringNotContainsString($gone, $json, $gone);
        }

        // 잠김 배지는 남는다 — 잠금 버튼은 관리자급에게만 보이기 때문이다.
        $this->assertContains('$t:g7-forum-addon.locked_badge', $texts);
    }

    public function test_every_on_state_fill_is_the_same_orange(): void
    {
        $filled = 0;
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            foreach ($this->buttons($this->node($method)) as $button) {
                $class = (string) ($button['props']['className'] ?? '');
                if (! str_contains($class, 'bg-orange-700')) {
                    continue;
                }
                $filled++;
                // 파랑·회색·초록 채움이 남아 있으면 안 된다.
                foreach (['bg-blue-600', 'bg-gray-600', 'bg-green-600'] as $old) {
                    $this->assertStringNotContainsString($old, $class, $method);
                }
            }
        }

        // 고정해제 · 잠금해제 · 채택취소 · 채택답변보기 = 4개
        $this->assertSame(4, $filled);
    }

    public function test_accepted_comment_toggle_switches_check_to_trophy(): void
    {
        $icons = [];
        foreach ($this->buttons($this->node('commentReactionBarNode')) as $button) {
            $pressed = $button['props']['aria-pressed'] ?? null;
            foreach ($button['children'] ?? [] as $child) {
                if (is_array($child) && ($child['name'] ?? null) === 'Icon') {
                    $icons[] = [$pressed, $child['props']['name'] ?? ''];
                }
            }
        }

        $this->assertContains([null, 'circle-check'], $icons, '미채택은 체크 아이콘');
        $this->assertContains(['true', 'trophy'], $icons, '채택되면 트로피 아이콘');
    }

    public function test_post_trophy_button_only_shows_when_an_answer_is_accepted(): void
    {
        $trophy = null;
        foreach ($this->buttons($this->node('widgetNode')) as $button) {
            foreach ($button['children'] ?? [] as $child) {
                if (is_array($child) && ($child['props']['name'] ?? null) === 'trophy') {
                    $trophy = $button;
                }
            }
        }

        $this->assertNotNull($trophy, '본글에 트로피 버튼이 있어야 한다');
        $this->assertSame('{{!!forum_meta?.data?.accepted_reply_id}}', $trophy['if'] ?? null);

        // 권한 게이트가 붙으면 안 된다 — 모두에게 보이는 버튼이다.
        $this->assertStringNotContainsString('can_manage', (string) ($trophy['if'] ?? ''));
    }

    public function test_no_green_class_remains_anywhere(): void
    {
        // 채택 관련 색은 1.4.0 에서 전부 주황으로 옮겼다. 초록이 남아 있으면
        // 어딘가 한 군데가 옛 색을 계속 쓰고 있다는 뜻이다.
        foreach (['widgetNode', 'commentReactionBarNode'] as $method) {
            $json = json_encode($this->node($method), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('green-', $json, $method);
        }
    }

    public function test_widget_is_a_single_button_row_with_no_preview_box(): void
    {
        $widget = $this->node('widgetNode');

        // 위젯의 직접 자식은 추천 바 · 잠김 배지 · 오른쪽 버튼 묶음 셋뿐이다.
        $this->assertCount(3, $widget['children']);

        // 채택 답변 전문을 다시 보여주던 박스는 없앴다 — 같은 내용이 아래 댓글
        // 목록에 이미 있고, 트로피 버튼이 그 자리로 데려다준다.
        $json = json_encode($widget, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('accepted_reply?.content', $json);
        $this->assertStringNotContainsString('accepted_reply?.author', $json);
        $this->assertStringNotContainsString('Avatar', $json);
    }

    public function test_post_trophy_navigates_by_scrolling_not_by_calling_the_api(): void
    {
        $trophy = null;
        foreach ($this->buttons($this->node('widgetNode')) as $button) {
            foreach ($button['children'] ?? [] as $child) {
                if (is_array($child) && ($child['props']['name'] ?? null) === 'trophy') {
                    $trophy = $button;
                }
            }
        }

        $action = $trophy['actions'][0] ?? [];
        $this->assertSame('replaceUrl', $action['handler'] ?? null);
        $this->assertStringStartsWith('#g7fa-comment-', (string) ($action['params']['scroll'] ?? ''));
        // API 호출이 아니다.
        $this->assertNotSame('apiCall', $action['handler'] ?? null);
    }
}
