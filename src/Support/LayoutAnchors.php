<?php

namespace Plugins\G7\Forum\Addon\Support;

/**
 * 템플릿이 달아 둔 앵커 표식(`data-g7-anchor`)을 읽는 도구 (1.5.0).
 *
 * 1.4.0 까지 `BoardShowWidgetListener` 는 템플릿 노드를 **모양**(className 토큰, `if` 문자열,
 * 자식 위치)으로 찾았다. 템플릿이 모양만 바꿔도 주입이 조용히 빠졌다(2026-09-26 실제 발생 —
 * 실패는 warning 이라 로그 레벨 error 에서 남지 않았다).
 *
 * 1.5.0 부터는 템플릿이 자리마다 전용 표식을 단다(wc-community). 표식은 노드면
 * `props["data-g7-anchor"]`, 액션 스텝(삭제 모달 `onSuccess`)이면 스텝의 `data-g7-anchor` 키다.
 * **어떤 자리의 표식이 트리에 하나라도 있으면 그 자리는 표식으로만 찾고, 하나도 없으면
 * 1.4.0 의 모양 기준 탐색으로 돌아간다**(sirsoft-basic 등 표식이 없는 템플릿).
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이다.
 */
final class LayoutAnchors
{
    public const ATTR = 'data-g7-anchor';

    /** 게시글 하단 액션 버튼 줄 — 그 앞에 포럼 위젯을 넣는다 */
    public const POST_ACTIONS = 'post-actions';

    /** 새 댓글 입력 폼 — 잠긴 글에서 가리고 안내로 바꾼다 */
    public const COMMENT_INPUT = 'comment-input';

    /** 댓글 행 컨테이너 — 채택 강조와 DOM id */
    public const COMMENT_ROW = 'comment-row';

    /** 읽기 모드 댓글 본문 — 그 뒤에 댓글 추천·채택 바를 넣는다 */
    public const COMMENT_BODY = 'comment-body';

    /** 답글 행 가시성 — 포럼은 기본 펼침 */
    public const REPLIES_ROW = 'comment-replies-row';

    /** "답글 보기 (N)" 토글 버튼 — 포럼은 기본 펼침(아이콘·문구·툴팁 포함) */
    public const REPLIES_TOGGLE = 'comment-replies-toggle';

    /** 삭제 모달의 "댓글 삭제 시 게시글 재조회" 스텝 — 그 뒤에 forum_meta 재조회를 붙인다 */
    public const DELETE_REFETCH = 'comment-delete-refetch';

    /** 점검 표시 순서 = 주입 7종 */
    public const ALL = [
        self::POST_ACTIONS,
        self::COMMENT_INPUT,
        self::COMMENT_BODY,
        self::COMMENT_ROW,
        self::REPLIES_ROW,
        self::REPLIES_TOGGLE,
        self::DELETE_REFETCH,
    ];

    /**
     * 트리(들)에 있는 표식 이름 집합.
     *
     * @param  array<mixed>  ...$trees  `components`, `modals` 등
     * @return array<string, true>
     */
    public static function present(array ...$trees): array
    {
        $found = [];
        foreach ($trees as $tree) {
            self::scan($tree, $found);
        }

        return $found;
    }

    /**
     * 노드(또는 액션 스텝)에 붙은 표식 이름.
     *
     * @param  mixed  $node
     */
    public static function of(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }
        $v = $node['props'][self::ATTR] ?? $node[self::ATTR] ?? null;

        return is_string($v) ? $v : null;
    }

    public static function is(mixed $node, string $name): bool
    {
        return self::of($node) === $name;
    }

    /**
     * 점검 표시 문자열 — 위젯 Div 의 `data-g7fa-anchors` 속성 값.
     * 예: `post-actions=marker:3,comment-input=shape:3,…` (`none:0` 은 못 찾은 자리)
     *
     * @param  array<string, array{via: string, count: int}>  $report
     */
    public static function summary(array $report): string
    {
        $parts = [];
        foreach (self::ALL as $name) {
            $r = $report[$name] ?? ['via' => 'none', 'count' => 0];
            $via = $r['count'] > 0 ? $r['via'] : 'none';
            $parts[] = $name.'='.$via.':'.$r['count'];
        }

        return implode(',', $parts);
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, true>  $found
     */
    private static function scan(array $node, array &$found): void
    {
        if (($name = self::of($node)) !== null) {
            $found[$name] = true;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::scan($value, $found);
            }
        }
    }
}
