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
        $allowed = ['chevron-up', 'chevron-down', 'bullhorn', 'lock', 'circle-check'];

        foreach (['widgetNode', 'commentReactionBarNode', 'acceptedReplyNode'] as $method) {
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
        foreach (['widgetNode', 'commentReactionBarNode', 'acceptedReplyNode'] as $method) {
            $json = json_encode($this->node($method), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString("':g7-forum-addon.", $json, $method);
            $this->assertStringNotContainsString('":g7-forum-addon.', $json, $method);
            $this->assertStringContainsString('$t:g7-forum-addon.', $json, $method);
        }
    }

    // ── 채택 버튼 이동 ───────────────────────────────────────

    public function test_accept_toggle_lives_in_the_comment_bar_not_the_badge_row(): void
    {
        $bar = $this->node('commentReactionBarNode');
        $badgeRow = $this->node('acceptedReplyNode');

        // 추천 2개 + 채택/채택취소 2개
        $this->assertCount(4, $this->buttons($bar));
        // "채택됨" 표시 줄에는 버튼이 없다.
        $this->assertCount(0, $this->buttons($badgeRow));
    }

    public function test_accepted_state_toggle_is_filled_green_and_reports_aria_pressed(): void
    {
        $accepted = null;
        foreach ($this->buttons($this->node('commentReactionBarNode')) as $button) {
            if (($button['props']['aria-pressed'] ?? null) === 'true'
                && str_contains((string) ($button['props']['className'] ?? ''), 'bg-green-600')) {
                $accepted = $button;
            }
        }

        $this->assertNotNull($accepted, '채택된 상태의 토글이 초록으로 채워져야 한다');
    }
}
