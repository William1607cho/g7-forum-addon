<?php

namespace Plugins\G7\Forum\Addon\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Forum\Addon\Support\RepliesDefaultExpanded as R;

/**
 * 답글 기본 펼침 식 변환 (1.5.0). 결과가 1.4.0 이 통째 문자열로 넣던 식과 같아야 한다.
 */
class RepliesDefaultExpandedTest extends TestCase
{
    private const DEF = "(post?.data?.board?.type === 'forum' ? false : true)";

    public function test_label_icon_and_setstate_match_the_1_4_0_patched_strings(): void
    {
        $this->assertSame(
            "{{!(_local.collapsedReplies?.[comment?.id] ?? ".self::DEF.") ? '\$t:board.hide_replies' : '\$t:board.show_replies'}} ({{comment?.replies_count}})",
            R::patch("{{_local.collapsedReplies?.[comment?.id] === false ? '\$t:board.hide_replies' : '\$t:board.show_replies'}} ({{comment?.replies_count}})")
        );
        $this->assertSame(
            "{{!(_local.collapsedReplies?.[comment?.id] ?? ".self::DEF.") ? 'chevron-up' : 'chevron-down'}}",
            R::patch("{{_local.collapsedReplies?.[comment?.id] === false ? 'chevron-up' : 'chevron-down'}}")
        );
        $this->assertSame(
            '{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? '.self::DEF.')})}}',
            R::patch('{{Object.assign({}, _local.collapsedReplies || {}, {[comment?.id]: !(_local.collapsedReplies?.[comment?.id] ?? true)})}}')
        );
    }

    public function test_nested_brackets_in_the_key(): void
    {
        $this->assertSame(
            '{{(comment?.depth ?? 0) === 0 || (!(_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] ?? '.self::DEF.'))}}',
            R::patch('{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false)}}')
        );
    }

    public function test_is_idempotent_and_leaves_other_text_alone(): void
    {
        $once = R::patch("{{_local.collapsedReplies?.[comment?.id] === false ? 'a' : 'b'}}");
        $this->assertSame($once, R::patch($once));
        foreach (['', 'plain', '{{_local.other?.[x] === false}}', '{{_local.collapsedReplies?.[x] === true}}', '_local.collapsedReplies?.[unclosed'] as $s) {
            $this->assertSame($s, R::patch($s));
        }
    }

    public function test_patch_tree_counts_changed_strings(): void
    {
        $n = 0;
        $out = R::patchTree([
            'props' => ['title' => '{{_local.collapsedReplies?.[c] === false}}', 'className' => 'x'],
            'children' => [['text' => '{{_local.collapsedReplies?.[c] ?? true}}'], ['text' => 'n']],
        ], $n);

        $this->assertSame(2, $n);
        $this->assertSame('x', $out['props']['className']);
        $this->assertSame('{{_local.collapsedReplies?.[c] ?? '.self::DEF.'}}', $out['children'][0]['text']);
    }
}
