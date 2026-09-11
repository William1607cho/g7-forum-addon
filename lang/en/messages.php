<?php

return [
    'uninstall' => [
        'boards_in_use' => "Cannot uninstall: :count board(s) still use the 'forum' type. Change those boards to another type or delete them, then try again.",
    ],
    'meta' => [
        'post_not_found' => 'Post not found.',
        'not_viewable' => 'This post is not viewable.',
    ],
    'comment' => [
        'locked' => 'This thread is locked. New comments are not allowed.',
    ],
    'lock' => [
        'locked' => 'The thread has been locked. New comments are now blocked.',
        'unlocked' => 'The thread has been unlocked.',
        'not_forum_post' => 'Only posts on a forum-type board can be locked.',
    ],
    'reaction' => [
        'ok' => 'Your reaction has been saved.',
        'login_required' => 'Please sign in to react.',
        'bad_target' => 'Reaction target not found.',
        'bad_reaction' => 'Unsupported reaction type.',
        'not_forum' => 'Reactions are only available on forum-type boards.',
    ],
    'accepted_reply' => [
        'accepted' => 'The answer has been accepted.',
        'unaccepted' => 'The accepted answer has been cleared.',
        'forbidden' => 'Only the post author or an administrator can accept an answer.',
        'bad_comment' => 'This comment cannot be accepted.',
        'not_forum' => 'Answers can only be accepted on forum-type boards.',
    ],
];
