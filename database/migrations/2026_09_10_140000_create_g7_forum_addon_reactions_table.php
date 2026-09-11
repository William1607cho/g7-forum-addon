<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 포럼게시판 애드온 — 리액션(추천/좋아요) 테이블.
 *
 * 게시글(`post`)·댓글(`comment`) 양쪽에 5종 이모지 리액션(like/love/haha/wow/sad)을 붙인다.
 * **1인 1리액션** — 한 사용자는 한 대상에 리액션 1개만 가질 수 있고, 다른 종류를 누르면
 * 교체되며, 같은 종류를 다시 누르면 취소된다. 이 불변조건을
 * `(target_type, target_id, user_id)` 유니크 제약으로 DB 레벨에서 강제한다.
 *
 * 비회원 리액션은 없다(로그인 사용자만) — `user_id` 는 NOT NULL.
 *
 * FK 는 걸지 않는다(post_meta 와 동일 방침 — 애드온이 대상 모듈 스키마에 물리적으로
 * 묶이지 않도록, 참조 무결성은 애플리케이션 계층에서). `board_id` 는 게시판 스코프
 * 조회·정리용.
 *
 * 물리 테이블명은 커넥션 prefix(`g7_`) 이중적용으로 `g7_g7_forum_addon_reactions`.
 * 자동 인덱스명이 64자를 넘을 수 있어 인덱스명을 명시적으로 짧게 준다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g7_forum_addon_reactions', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('target_type', 16)->comment("대상 유형: 'post' | 'comment'");
            $table->unsignedBigInteger('target_id')->comment('board_posts.id 또는 board_comments.id');
            $table->unsignedBigInteger('board_id')->comment('boards.id (게시판 스코프·정리용)');
            $table->unsignedBigInteger('user_id')->comment('users.id (비회원 리액션 없음)');
            $table->string('reaction', 16)->comment('like | love | haha | wow | sad');
            $table->timestamps();

            // 1인 1리액션 — DB 레벨 강제.
            $table->unique(['target_type', 'target_id', 'user_id'], 'g7fa_react_target_user_unq');
            // 대상별 집계 조회.
            $table->index(['target_type', 'target_id'], 'g7fa_react_target_idx');
            $table->index('board_id', 'g7fa_react_board_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('g7_forum_addon_reactions', function (Blueprint $table) {
                $table->comment('포럼게시판 애드온 — 게시글/댓글 리액션(1인 1리액션)');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g7_forum_addon_reactions');
    }
};
