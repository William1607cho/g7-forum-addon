<?php

return [
    'uninstall' => [
        'boards_in_use' => "포럼형('forum') 유형을 사용하는 게시판이 :count개 있어 제거할 수 없습니다. 해당 게시판을 다른 유형으로 변경하거나 삭제한 뒤 다시 시도하세요.",
    ],
    'meta' => [
        'post_not_found' => '게시글을 찾을 수 없습니다.',
        'not_viewable' => '열람할 수 없는 게시글입니다.',
    ],
    'comment' => [
        'locked' => '잠긴 게시글입니다. 새 댓글을 작성할 수 없습니다.',
    ],
    'lock' => [
        'locked' => '게시글을 잠갔습니다. 새 댓글 작성이 차단됩니다.',
        'unlocked' => '게시글 잠금을 해제했습니다.',
        'not_forum_post' => '포럼형 게시판의 게시글만 잠글 수 있습니다.',
    ],
    'reaction' => [
        'ok' => '리액션을 반영했습니다.',
        'login_required' => '리액션은 로그인 후 이용할 수 있습니다.',
        'bad_target' => '리액션 대상을 찾을 수 없습니다.',
        'bad_reaction' => '지원하지 않는 리액션 종류입니다.',
        'not_forum' => '포럼형 게시판에서만 리액션할 수 있습니다.',
    ],
    'accepted_reply' => [
        'accepted' => '답변을 채택했습니다.',
        'unaccepted' => '답변 채택을 해제했습니다.',
        'forbidden' => '답변 채택은 글 작성자 또는 관리자만 할 수 있습니다.',
        'bad_comment' => '채택할 수 없는 댓글입니다.',
        'not_forum' => '포럼형 게시판에서만 답변을 채택할 수 있습니다.',
    ],
];
