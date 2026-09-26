<?php

namespace Plugins\G7\Forum\Addon\Support;

/**
 * "답글 보기 (N)" 식을 포럼 게시판에서만 "기본 펼침" 으로 바꾸는 식 변환기 (1.5.0).
 *
 * 템플릿의 답글 표시는 `_local.collapsedReplies[id]` 값이 없으면 접힘이다.
 * 포럼 게시판에서는 값이 없을 때 펼침이어야 하므로, 식 안의 두 모양을 바꾼다.
 *
 *   `_local.collapsedReplies?.[X] === false`  →  `!(_local.collapsedReplies?.[X] ?? DEF)`
 *   `_local.collapsedReplies?.[X] ?? true`    →  `_local.collapsedReplies?.[X] ?? DEF`
 *
 * DEF = `(post?.data?.board?.type === 'forum' ? false : true)` — 포럼이면 "접힘 아님".
 * X 는 대괄호가 겹쳐도 된다(`[$computed.commentRootMap?.[comment?.id]]`).
 *
 * 1.4.0 은 식 네 개를 **통째 문자열**로 비교해 바꿨고, 버튼의 `title`(툴팁)은 바꾸지 않아
 * 포럼 첫 화면에서 툴팁이 "답글 보기" 로 반대였다. 이 변환기는 모양 단위로 바꾸므로
 * `title`·화면낭독 문구·아이콘·클릭 갱신식이 모두 같은 규칙으로 바뀐다.
 * 이미 바뀐 식에는 두 모양이 더 없으므로 다시 돌려도 그대로다(멱등).
 */
final class RepliesDefaultExpanded
{
    public const DEF = "(post?.data?.board?.type === 'forum' ? false : true)";

    private const NEEDLE = '_local.collapsedReplies?.[';

    public static function patch(string $expr): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($expr);

        while (($at = strpos($expr, self::NEEDLE, $pos)) !== false) {
            $end = self::closingBracket($expr, $at + strlen(self::NEEDLE) - 1);
            if ($end === null) {
                break;
            }
            $access = substr($expr, $at, $end - $at + 1);
            $rest = substr($expr, $end + 1);

            if (str_starts_with($rest, ' === false')) {
                $out .= substr($expr, $pos, $at - $pos).'!('.$access.' ?? '.self::DEF.')';
                $pos = $end + 1 + strlen(' === false');
            } elseif (str_starts_with($rest, ' ?? true')) {
                $out .= substr($expr, $pos, $at - $pos).$access.' ?? '.self::DEF;
                $pos = $end + 1 + strlen(' ?? true');
            } else {
                $out .= substr($expr, $pos, $end + 1 - $pos);
                $pos = $end + 1;
            }
        }

        return $out.substr($expr, $pos, $len - $pos);
    }

    /**
     * 노드(하위 전체)의 모든 문자열 값에 {@see patch()} 를 적용한다.
     *
     * @param  array<mixed>  $node
     * @param  int  $changed  (참조) 바뀐 문자열 수
     * @return array<mixed>
     */
    public static function patchTree(array $node, int &$changed): array
    {
        foreach ($node as $k => $v) {
            if (is_string($v)) {
                $p = self::patch($v);
                if ($p !== $v) {
                    $node[$k] = $p;
                    $changed++;
                }
            } elseif (is_array($v)) {
                $node[$k] = self::patchTree($v, $changed);
            }
        }

        return $node;
    }

    /** 여는 대괄호 위치에서 짝이 맞는 닫는 대괄호 위치 */
    private static function closingBracket(string $s, int $open): ?int
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $open; $i < $len; $i++) {
            if ($s[$i] === '[') {
                $depth++;
            } elseif ($s[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
